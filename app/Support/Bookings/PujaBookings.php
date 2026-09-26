<?php

namespace App\Support\Bookings;

use App\Enums\BookingStatus;
use App\Enums\TempleStatus;
use App\Models\Devotee;
use App\Models\Payment;
use App\Models\PujaBooking;
use App\Models\TemplePuja;
use App\Models\User;
use App\Support\DevotionalClock;
use App\Support\Payments\Payments;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Booking a seva through the app, and what happens to the booking after.
 *
 * The rules live here rather than in the controller or the panel so that
 * the app, the temple portal and the admin land on the same answers: a
 * free seva is confirmed at once; a priced one waits for its payment and is
 * confirmed by the payment, never by the app's word; a code is verified
 * once and refused the second time.
 */
final class PujaBookings
{
    public const VERIFIED = 'verified';

    public const ALREADY_VERIFIED = 'already_verified';

    public function __construct(protected Payments $payments) {}

    /**
     * Places a booking. Returns it with its payment (if one is due) attached.
     *
     * @param  array{booked_for: string, people: int, devotee_name?: ?string, devotee_phone?: ?string, gotram?: ?string, nakshatram?: ?string, note?: ?string}  $data
     *
     * @throws ValidationException
     */
    public function place(Devotee $devotee, TemplePuja $puja, array $data, ?string $gateway = null): PujaBooking
    {
        $puja->loadMissing('temple');

        if ($puja->temple?->status !== TempleStatus::Published || ! $puja->isBookableInApp()) {
            throw ValidationException::withMessages(['puja' => 'This seva cannot be booked through the app.']);
        }

        $today = DevotionalClock::now()->startOfDay();
        $for = \Carbon\CarbonImmutable::parse($data['booked_for'], DevotionalClock::timezone())->startOfDay();

        if ($for->lt($today)) {
            throw ValidationException::withMessages(['booked_for' => 'That day has passed.']);
        }

        if ($for->gt($puja->lastBookableDate())) {
            throw ValidationException::withMessages(['booked_for' => 'This seva can be booked up to '.$puja->booking_advance_days.' days ahead.']);
        }

        $people = max(1, (int) ($data['people'] ?? 1));

        if ($people > $puja->max_people_per_booking) {
            throw ValidationException::withMessages(['people' => 'Up to '.$puja->max_people_per_booking.' people per booking.']);
        }

        $amount = $puja->amountPaiseFor($people);

        if ($amount > 0) {
            $gateway ??= \App\Support\AppConfig::payments('android')['default_gateway'] ?? null;

            if ($gateway === null || ! in_array($gateway, \App\Support\AppConfig::enabledGateways(), true)) {
                throw ValidationException::withMessages(['gateway' => 'Payments are not open yet. Book at the temple counter for now.']);
            }
        }

        return DB::transaction(function () use ($devotee, $puja, $data, $for, $people, $amount, $gateway): PujaBooking {
            // The capacity check and the insert under one lock, so two
            // devotees taking the last slot together do not both get it.
            $locked = TemplePuja::query()->lockForUpdate()->findOrFail($puja->getKey());
            $remaining = $locked->remainingCapacityOn($for);

            if ($remaining !== null && $remaining <= 0) {
                throw ValidationException::withMessages(['booked_for' => 'This seva is fully booked on that day. Choose another day.']);
            }

            $booking = new PujaBooking([
                'temple_id' => $locked->temple_id,
                'temple_puja_id' => $locked->getKey(),
                'devotee_id' => $devotee->getKey(),
                'booked_for' => $for->toDateString(),
                'people' => $people,
                'devotee_name' => filled($data['devotee_name'] ?? null) ? $data['devotee_name'] : $devotee->name,
                'devotee_phone' => filled($data['devotee_phone'] ?? null) ? $data['devotee_phone'] : $devotee->phone,
                'gotram' => $data['gotram'] ?? null,
                'nakshatram' => $data['nakshatram'] ?? null,
                'note' => $data['note'] ?? null,
                'amount_paise' => $amount,
                'currency' => $locked->fee_currency ?: 'INR',
                'status' => $amount === 0 ? BookingStatus::Confirmed : BookingStatus::PendingPayment,
            ]);

            if ($amount === 0) {
                $booking->confirmed_at = now();
            }

            $booking->save();

            if ($amount > 0) {
                $payment = $this->payments->beginFor($devotee, $amount, Payment::PUJA_BOOKING, $gateway, [
                    'booking' => $booking->reference,
                ]);
                $booking->payment()->associate($payment);
                $booking->save();
            }

            return $booking->load(['puja', 'temple', 'payment']);
        });
    }

    /** The payment for this booking went through: the temple should expect them. */
    public function confirm(PujaBooking $booking): PujaBooking
    {
        if ($booking->status === BookingStatus::PendingPayment) {
            $booking->forceFill(['status' => BookingStatus::Confirmed, 'confirmed_at' => now()])->save();
        }

        return $booking;
    }

    /** The payment failed or was abandoned: nothing is owed and nobody is expected. */
    public function paymentFailed(PujaBooking $booking, ?string $reason = null): PujaBooking
    {
        if ($booking->status === BookingStatus::PendingPayment) {
            $booking->forceFill([
                'status' => BookingStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => 'system',
                'cancel_reason' => $reason ?? 'Payment was not completed.',
            ])->save();
        }

        return $booking;
    }

    /** The money went back: the booking is void whatever state it was in. */
    public function refunded(PujaBooking $booking): PujaBooking
    {
        $booking->forceFill([
            'status' => BookingStatus::Refunded,
            'cancelled_at' => $booking->cancelled_at ?? now(),
            'cancelled_by' => $booking->cancelled_by ?? 'temple',
        ])->save();

        return $booking;
    }

    /**
     * Cancelled by whoever asked: the devotee from the app, the temple from
     * its portal, staff from the admin. A paid booking is not refunded here;
     * the temple refunds in the gateway and records it, and the status then
     * moves on to refunded.
     */
    public function cancel(PujaBooking $booking, string $by, ?string $reason = null): PujaBooking
    {
        if (! in_array($booking->status, [BookingStatus::PendingPayment, BookingStatus::Confirmed], true)) {
            throw ValidationException::withMessages(['status' => 'This booking can no longer be cancelled.']);
        }

        $booking->forceFill([
            'status' => BookingStatus::Cancelled,
            'cancelled_at' => now(),
            'cancelled_by' => $by,
            'cancel_reason' => $reason,
        ])->save();

        // An unpaid payment is closed so the gateway is not asked about it
        // for the next day.
        if ($booking->payment !== null && ! $booking->payment->isSettled()) {
            $this->payments->apply($booking->payment, Payment::FAILED, reason: 'Booking cancelled.');
        }

        return $booking;
    }

    /**
     * The counter scanned the code.
     *
     * Once: the first scan marks it verified and says who did it; a second
     * scan is refused with when and by whom, so the same screenshot cannot
     * be presented twice. Only a temple the staff member manages, or
     * editorial staff, can verify.
     *
     * @return array{outcome: string, booking: PujaBooking}
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function verify(PujaBooking $booking, User $staff): array
    {
        if (! ($staff->role?->isStaff() ?? false) && ! $staff->administersTemple($booking->temple_id)) {
            throw new AuthorizationException('You can verify bookings only at temples you manage.');
        }

        return DB::transaction(function () use ($booking, $staff): array {
            $locked = PujaBooking::query()->lockForUpdate()->findOrFail($booking->getKey());

            if ($locked->status === BookingStatus::Verified) {
                return ['outcome' => self::ALREADY_VERIFIED, 'booking' => $locked];
            }

            if ($locked->status !== BookingStatus::Confirmed) {
                throw ValidationException::withMessages(['status' => match ($locked->status) {
                    BookingStatus::PendingPayment => 'This booking has not been paid for.',
                    BookingStatus::Cancelled => 'This booking was cancelled.',
                    BookingStatus::Refunded => 'This booking was refunded.',
                    default => 'This booking cannot be verified.',
                }]);
            }

            $locked->forceFill([
                'status' => BookingStatus::Verified,
                'verified_at' => now(),
                'verified_by' => $staff->getKey(),
            ])->save();

            return ['outcome' => self::VERIFIED, 'booking' => $locked];
        });
    }
}
