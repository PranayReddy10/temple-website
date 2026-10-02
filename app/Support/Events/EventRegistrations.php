<?php

namespace App\Support\Events;

use App\Enums\BookingStatus;
use App\Enums\EventStatus;
use App\Enums\TempleStatus;
use App\Models\Devotee;
use App\Models\EventRegistration;
use App\Models\Payment;
use App\Models\TempleEvent;
use App\Models\User;
use App\Support\AppConfig;
use App\Support\DevotionalClock;
use App\Support\Payments\Payments;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Joining a temple event: "I'll join" for a free bhajan gathering, tickets
 * for a paid event. The same rules as seva bookings (PujaBookings), so the
 * app, the trust app and the admin agree: free is confirmed at once, paid
 * waits for the gateway's word, a code is received once at the gate, and
 * a ticket for a day that has passed expires.
 */
final class EventRegistrations
{
    public const VERIFIED = 'verified';

    public const ALREADY_VERIFIED = 'already_verified';

    public function __construct(protected Payments $payments) {}

    /**
     * @param  array{occurs_on?: ?string, people?: ?int, devotee_name?: ?string, devotee_phone?: ?string}  $data
     *
     * @throws ValidationException
     */
    public function join(Devotee $devotee, TempleEvent $event, array $data, ?string $gateway = null): EventRegistration
    {
        $event->loadMissing('temple');

        if ($event->status !== EventStatus::Published || $event->temple?->status !== TempleStatus::Published || ! $event->registration_enabled) {
            throw ValidationException::withMessages(['event' => 'This event does not take registrations in the app.']);
        }

        $today = CarbonImmutable::parse(DevotionalClock::now()->toDateString(), 'UTC');
        $date = filled($data['occurs_on'] ?? null)
            ? CarbonImmutable::parse($data['occurs_on'], 'UTC')
            : $event->nextDate($today);

        if ($date === null || $date->lt($today) || ! $event->occursOn($date)) {
            throw ValidationException::withMessages(['occurs_on' => 'The event does not take place on that day.']);
        }

        $people = max(1, (int) ($data['people'] ?? 1));
        if ($people > $event->max_people_per_registration) {
            throw ValidationException::withMessages(['people' => 'Up to '.$event->max_people_per_registration.' people at a time.']);
        }

        $amount = $event->amountPaiseFor($people);

        if ($amount > 0) {
            $gateway ??= AppConfig::payments('android')['default_gateway'] ?? null;
            if ($gateway === null || ! in_array($gateway, AppConfig::enabledGateways(), true)) {
                throw ValidationException::withMessages(['gateway' => 'Payments are not open yet. Buy tickets at the temple for now.']);
            }
        }

        return DB::transaction(function () use ($devotee, $event, $data, $date, $people, $amount, $gateway): EventRegistration {
            $locked = TempleEvent::query()->lockForUpdate()->findOrFail($event->getKey());

            // One place per devotee per date: joining again changes nothing.
            $existing = $locked->registrations()
                ->where('devotee_id', $devotee->getKey())
                ->whereDate('occurs_on', $date->toDateString())
                ->whereIn('status', [BookingStatus::Confirmed->value, BookingStatus::Verified->value, BookingStatus::PendingPayment->value])
                ->first();
            if ($existing !== null) {
                throw ValidationException::withMessages(['occurs_on' => $existing->status === BookingStatus::PendingPayment
                    ? 'You already have tickets for this day waiting for payment. Open them under My tickets to pay.'
                    : 'You are already registered for this day.']);
            }

            $left = $locked->remainingOn($date);
            if ($left !== null && $left < $people) {
                throw ValidationException::withMessages(['people' => $left === 0
                    ? 'This day is full.'
                    : 'Only '.$left.' '.($left === 1 ? 'place is' : 'places are').' left on this day.']);
            }

            $registration = new EventRegistration([
                'temple_event_id' => $locked->getKey(),
                'temple_id' => $locked->temple_id,
                'devotee_id' => $devotee->getKey(),
                'occurs_on' => $date->toDateString(),
                'people' => $people,
                'devotee_name' => filled($data['devotee_name'] ?? null) ? $data['devotee_name'] : $devotee->name,
                'devotee_phone' => filled($data['devotee_phone'] ?? null) ? $data['devotee_phone'] : $devotee->phone,
                'amount_paise' => $amount,
                'status' => $amount === 0 ? BookingStatus::Confirmed : BookingStatus::PendingPayment,
            ]);

            if ($amount === 0) {
                $registration->confirmed_at = now();
            }

            $registration->save();

            if ($amount > 0) {
                $payment = $this->payments->beginFor($devotee, $amount, Payment::EVENT_TICKET, $gateway, ['registration' => $registration->reference]);
                $registration->payment()->associate($payment);
                $registration->save();
            }

            return $registration->load(['event', 'temple', 'payment']);
        });
    }

    /** "Pay now" again for tickets still waiting for their money. */
    public function retryPayment(EventRegistration $registration, ?string $gateway = null): EventRegistration
    {
        $registration->loadMissing('payment');

        if ($registration->payment !== null && ! $registration->payment->isSettled()) {
            try {
                $this->payments->reconcile($registration->payment);
            } catch (\Throwable) {
            }
            $registration->refresh();
        }

        if (! $registration->canBePaidFor()) {
            throw ValidationException::withMessages(['status' => $registration->isLive()
                ? 'These tickets are already paid.'
                : 'These tickets can no longer be paid for. Book again.']);
        }

        $gateway ??= $registration->payment?->gateway ?? AppConfig::payments('android')['default_gateway'] ?? null;
        if ($gateway === null || ! in_array($gateway, AppConfig::enabledGateways(), true)) {
            throw ValidationException::withMessages(['gateway' => 'Payments are not open yet.']);
        }

        $payment = $this->payments->beginFor($registration->devotee, $registration->amount_paise, Payment::EVENT_TICKET, $gateway, ['registration' => $registration->reference]);
        $registration->payment()->associate($payment);
        $registration->save();

        return $registration->load(['event', 'temple', 'payment']);
    }

    public function confirm(EventRegistration $registration): EventRegistration
    {
        if ($registration->status === BookingStatus::PendingPayment) {
            $registration->forceFill(['status' => BookingStatus::Confirmed, 'confirmed_at' => now()])->save();
        }

        return $registration;
    }

    public function paymentFailed(EventRegistration $registration, ?string $reason = null): EventRegistration
    {
        if ($registration->status === BookingStatus::PendingPayment) {
            $registration->forceFill([
                'status' => BookingStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => 'system',
                'cancel_reason' => $reason ?? 'Payment was not completed.',
            ])->save();
        }

        return $registration;
    }

    public function refunded(EventRegistration $registration): EventRegistration
    {
        $registration->forceFill([
            'status' => BookingStatus::Refunded,
            'cancelled_at' => $registration->cancelled_at ?? now(),
            'cancelled_by' => $registration->cancelled_by ?? 'temple',
        ])->save();

        return $registration;
    }

    public function cancel(EventRegistration $registration, string $by, ?string $reason = null): EventRegistration
    {
        if (! in_array($registration->status, [BookingStatus::PendingPayment, BookingStatus::Confirmed], true)) {
            throw ValidationException::withMessages(['status' => 'This can no longer be cancelled.']);
        }

        if ($registration->settlement_id !== null) {
            throw ValidationException::withMessages(['status' => 'These tickets are part of a settlement to the temple and can no longer be cancelled.']);
        }

        $registration->forceFill([
            'status' => BookingStatus::Cancelled,
            'cancelled_at' => now(),
            'cancelled_by' => $by,
            'cancel_reason' => $reason,
        ])->save();

        if ($registration->payment !== null && ! $registration->payment->isSettled()) {
            $this->payments->apply($registration->payment, Payment::FAILED, reason: 'Tickets cancelled.');
        }

        return $registration;
    }

    /** Places for days that have passed: expired if not received, cancelled if never paid. */
    public function expireOverdue(?Builder $scope = null): int
    {
        $today = DevotionalClock::now()->toDateString();
        $base = fn () => ($scope ? clone $scope : EventRegistration::query())->whereDate('occurs_on', '<', $today);

        $expired = $base()->where('status', BookingStatus::Confirmed->value)
            ->update(['status' => BookingStatus::Expired->value, 'expired_at' => now(), 'updated_at' => now()]);

        $unpaid = $base()->where('status', BookingStatus::PendingPayment->value)
            ->update([
                'status' => BookingStatus::Cancelled->value,
                'cancelled_at' => now(),
                'cancelled_by' => 'system',
                'cancel_reason' => 'Not paid for before the day.',
                'updated_at' => now(),
            ]);

        return $expired + $unpaid;
    }

    /**
     * The gate scanned the code: received once, on its day only.
     *
     * @return array{outcome: string, registration: EventRegistration}
     */
    public function verify(EventRegistration $registration, User $staff): array
    {
        if (! ($staff->role?->isStaff() ?? false) && ! $staff->administersTemple($registration->temple_id)) {
            throw new AuthorizationException('You can verify tickets only at temples you manage.');
        }

        return DB::transaction(function () use ($registration, $staff): array {
            $locked = EventRegistration::query()->lockForUpdate()->findOrFail($registration->getKey());

            if ($locked->status === BookingStatus::Verified) {
                return ['outcome' => self::ALREADY_VERIFIED, 'registration' => $locked];
            }

            $today = DevotionalClock::now()->toDateString();
            if ($locked->status === BookingStatus::Confirmed && $locked->occurs_on->toDateString() < $today) {
                $this->expireOverdue(EventRegistration::query()->whereKey($locked->getKey()));
                $locked->refresh();
            }
            if ($locked->status === BookingStatus::Confirmed && $locked->occurs_on->toDateString() > $today) {
                throw ValidationException::withMessages(['status' => 'This ticket is for '.$locked->occurs_on->format('j M Y').'. It is valid on that day only.']);
            }

            if ($locked->status !== BookingStatus::Confirmed) {
                throw ValidationException::withMessages(['status' => match ($locked->status) {
                    BookingStatus::PendingPayment => 'This ticket has not been paid for.',
                    BookingStatus::Cancelled => 'This ticket was cancelled.',
                    BookingStatus::Refunded => 'This ticket was refunded.',
                    BookingStatus::Expired => 'This ticket expired: it was for '.$locked->occurs_on->format('j M Y').'.',
                    default => 'This ticket cannot be verified.',
                }]);
            }

            $locked->forceFill([
                'status' => BookingStatus::Verified,
                'verified_at' => now(),
                'verified_by' => $staff->getKey(),
            ])->save();

            return ['outcome' => self::VERIFIED, 'registration' => $locked];
        });
    }
}
