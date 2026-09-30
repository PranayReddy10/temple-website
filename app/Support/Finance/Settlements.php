<?php

namespace App\Support\Finance;

use App\Models\PujaBooking;
use App\Models\Setting;
use App\Models\Temple;
use App\Models\TempleSettlement;
use App\Models\User;
use App\Support\DevotionalClock;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What the platform owes each temple for its seva bookings, and paying it.
 *
 * Devotees pay the platform's gateway. A booking becomes the temple's money
 * once it is paid and still live; it is settled once its seva day has come,
 * so a cancellation before the day never has to be clawed back. Preparing a
 * settlement locks its bookings to it; marking it paid records the transfer;
 * cancelling it releases them into the next one. Nothing here moves money:
 * the transfer is made from the bank and recorded.
 *
 * The admin panel and the trust app both go through here, so either can
 * finish what the other started.
 */
class Settlements
{
    /** Default fee in percent, when a temple has none of its own. */
    public function defaultFeePercent(): float
    {
        return max(0.0, min(100.0, (float) Setting::get('finance_platform_fee_percent', 0)));
    }

    public function feePercentFor(Temple $temple): float
    {
        $own = $temple->payoutAccount?->platform_fee_percent;

        return $own === null ? $this->defaultFeePercent() : max(0.0, min(100.0, (float) $own));
    }

    public static function feeOf(int $grossPaise, float $percent): int
    {
        return (int) round($grossPaise * $percent / 100);
    }

    /** The last seva day a settlement made now would cover: yesterday. */
    public function defaultCutoff(): CarbonInterface
    {
        return DevotionalClock::now()->subDay()->startOfDay();
    }

    /** Paid bookings not yet in a settlement, whose day is on or before $upTo. */
    public function settleableQuery(Temple|int $temple, CarbonInterface|string|null $upTo = null): Builder
    {
        $id = $temple instanceof Temple ? $temple->getKey() : $temple;

        return PujaBooking::query()
            ->where('temple_id', $id)
            ->settleable()
            ->when($upTo !== null, fn (Builder $q) => $q->whereDate('booked_for', '<=', $upTo instanceof CarbonInterface ? $upTo->toDateString() : $upTo));
    }

    /**
     * A temple's position: what is ready to settle, what is paid but its day
     * has not come, what is being paid out, and what has been paid.
     */
    public function balance(Temple $temple): array
    {
        $percent = $this->feePercentFor($temple);
        $cutoff = $this->defaultCutoff()->toDateString();

        $ready = $this->settleableQuery($temple, $cutoff)->selectRaw('count(*) as n, coalesce(sum(amount_paise), 0) as gross')->first();
        $all = $this->settleableQuery($temple)->selectRaw('count(*) as n, coalesce(sum(amount_paise), 0) as gross')->first();

        $readyGross = (int) $ready->gross;
        $allGross = (int) $all->gross;
        $readyFee = self::feeOf($readyGross, $percent);

        $pending = $temple->settlements()->pending()->selectRaw('count(*) as n, coalesce(sum(net_paise), 0) as net')->first();
        $paid = $temple->settlements()->paid()->selectRaw('count(*) as n, coalesce(sum(net_paise), 0) as net, coalesce(sum(fee_paise), 0) as fee, max(paid_at) as last_paid_at')->first();

        return [
            'fee_percent' => $percent,
            'cutoff' => $cutoff,
            'ready' => [
                'bookings' => (int) $ready->n,
                'gross_paise' => $readyGross,
                'fee_paise' => $readyFee,
                'net_paise' => $readyGross - $readyFee,
            ],
            // Paid by devotees, for days still ahead: settled after the day.
            'upcoming' => [
                'bookings' => (int) $all->n - (int) $ready->n,
                'gross_paise' => $allGross - $readyGross,
            ],
            'in_payout' => [
                'settlements' => (int) $pending->n,
                'net_paise' => (int) $pending->net,
            ],
            'paid' => [
                'settlements' => (int) $paid->n,
                'net_paise' => (int) $paid->net,
                'fee_paise' => (int) $paid->fee,
                'last_paid_at' => $paid->last_paid_at === null ? null : Carbon::parse($paid->last_paid_at)->toIso8601String(),
            ],
        ];
    }

    /**
     * Bookings and money for one seva day, for the counter and the office:
     * everything booked for the day, what was paid, and who has come.
     */
    public function day(Temple $temple, CarbonInterface|string $day): array
    {
        $date = $day instanceof CarbonInterface ? $day->toDateString() : $day;

        $rows = $temple->pujaBookings()
            ->whereDate('booked_for', $date)
            ->selectRaw('status, count(*) as n, coalesce(sum(people), 0) as people, coalesce(sum(amount_paise), 0) as amount')
            ->groupBy('status')
            ->get()
            ->keyBy(fn ($r) => $r->status instanceof \BackedEnum ? $r->status->value : (string) $r->status);

        $n = fn (string $s, string $k): int => (int) ($rows[$s]->{$k} ?? 0);

        $bySeva = $temple->pujaBookings()
            ->live()
            ->whereDate('booked_for', $date)
            ->join('temple_pujas', 'temple_pujas.id', '=', 'puja_bookings.temple_puja_id')
            ->selectRaw('temple_pujas.name as seva, count(*) as n, coalesce(sum(puja_bookings.people), 0) as people, coalesce(sum(puja_bookings.amount_paise), 0) as amount')
            ->groupBy('temple_pujas.name')
            ->orderByDesc('amount')
            ->get()
            ->map(fn ($r): array => [
                'seva' => $r->seva,
                'bookings' => (int) $r->n,
                'people' => (int) $r->people,
                'amount_paise' => (int) $r->amount,
                'amount' => TempleSettlement::rupees((int) $r->amount),
            ])
            ->values()
            ->all();

        $liveBookings = $n('confirmed', 'n') + $n('verified', 'n');
        $liveAmount = $n('confirmed', 'amount') + $n('verified', 'amount');

        return [
            'date' => $date,
            'bookings' => $liveBookings,
            'people' => $n('confirmed', 'people') + $n('verified', 'people'),
            'received' => $n('verified', 'n'),
            'to_receive' => $n('confirmed', 'n'),
            'amount_paise' => $liveAmount,
            'amount' => TempleSettlement::rupees($liveAmount),
            'awaiting_payment' => $n('pending_payment', 'n'),
            'cancelled' => $n('cancelled', 'n') + $n('refunded', 'n'),
            'refunded_paise' => $n('refunded', 'amount'),
            'by_seva' => $bySeva,
        ];
    }

    /** Booked and paid over a range of seva days. */
    public function period(Temple $temple, CarbonInterface $from, CarbonInterface $to): array
    {
        $row = $temple->pujaBookings()
            ->live()
            ->whereDate('booked_for', '>=', $from->toDateString())
            ->whereDate('booked_for', '<=', $to->toDateString())
            ->selectRaw('count(*) as n, coalesce(sum(people), 0) as people, coalesce(sum(amount_paise), 0) as amount')
            ->first();

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'bookings' => (int) $row->n,
            'people' => (int) $row->people,
            'amount_paise' => (int) $row->amount,
            'amount' => TempleSettlement::rupees((int) $row->amount),
        ];
    }

    /**
     * Gathers a temple's paid bookings up to $upTo into a settlement to pay.
     *
     * @throws ValidationException when there is nothing to settle
     */
    public function create(Temple $temple, CarbonInterface|string|null $upTo, ?User $by, ?string $note = null): TempleSettlement
    {
        $cutoff = $upTo === null
            ? $this->defaultCutoff()
            : ($upTo instanceof CarbonInterface ? $upTo : Carbon::parse($upTo));

        if ($cutoff->toDateString() > DevotionalClock::now()->toDateString()) {
            throw ValidationException::withMessages(['up_to' => 'A settlement covers seva days that have come, up to today at the latest.']);
        }

        return DB::transaction(function () use ($temple, $cutoff, $by, $note): TempleSettlement {
            // Locked, so two people settling at once cannot take the same booking.
            $bookings = $this->settleableQuery($temple, $cutoff)->lockForUpdate()->get(['id', 'amount_paise', 'booked_for']);

            if ($bookings->isEmpty()) {
                throw ValidationException::withMessages(['up_to' => 'Nothing to settle: no paid bookings up to '.$cutoff->format('d M Y').' that are not already settled.']);
            }

            $percent = $this->feePercentFor($temple);
            $gross = (int) $bookings->sum('amount_paise');
            $fee = self::feeOf($gross, $percent);
            $account = $temple->payoutAccount;

            $settlement = $temple->settlements()->create([
                'period_from' => $bookings->min('booked_for'),
                'period_to' => $cutoff->toDateString(),
                'bookings_count' => $bookings->count(),
                'gross_paise' => $gross,
                'fee_percent' => $percent,
                'fee_paise' => $fee,
                'net_paise' => $gross - $fee,
                'payout_to' => $account?->isComplete() ? json_encode([
                    'account_name' => $account->account_name,
                    'account_number' => $account->account_number,
                    'ifsc' => $account->ifsc,
                    'bank_name' => $account->bank_name,
                    'upi_id' => $account->upi_id,
                    'verified' => $account->isVerified(),
                ]) : null,
                'note' => $note,
                'created_by' => $by?->getKey(),
            ]);

            PujaBooking::query()->whereKey($bookings->modelKeys())->update(['settlement_id' => $settlement->getKey()]);

            return $settlement;
        });
    }

    /** Records the transfer made from the bank. */
    public function markPaid(TempleSettlement $settlement, string $method, ?string $transactionRef, ?User $by, CarbonInterface|string|null $paidAt = null, ?string $note = null): TempleSettlement
    {
        if (! $settlement->isPending()) {
            throw ValidationException::withMessages(['status' => 'Only a settlement waiting to be paid can be marked paid.']);
        }

        if (! array_key_exists($method, TempleSettlement::METHODS)) {
            throw ValidationException::withMessages(['method' => 'Choose how it was paid.']);
        }

        if (in_array($method, ['bank', 'upi', 'cheque'], true) && blank($transactionRef)) {
            throw ValidationException::withMessages(['transaction_ref' => 'Enter the bank reference (UTR) or cheque number.']);
        }

        $settlement->forceFill([
            'status' => TempleSettlement::PAID,
            'method' => $method,
            'transaction_ref' => $transactionRef === null ? null : trim($transactionRef),
            'paid_at' => $paidAt === null ? now() : Carbon::parse($paidAt),
            'paid_by' => $by?->getKey(),
            'note' => filled($note) ? $note : $settlement->note,
        ])->save();

        return $settlement;
    }

    /** Releases its bookings, to be settled again in the next one. */
    public function cancel(TempleSettlement $settlement, ?string $reason = null): TempleSettlement
    {
        if (! $settlement->isPending()) {
            throw ValidationException::withMessages(['status' => 'A paid settlement cannot be cancelled.']);
        }

        return DB::transaction(function () use ($settlement, $reason): TempleSettlement {
            $settlement->bookings()->update(['settlement_id' => null]);
            $settlement->forceFill(['status' => TempleSettlement::CANCELLED, 'cancel_reason' => $reason])->save();

            return $settlement;
        });
    }

    /** Payout details as staff read them to make the transfer. */
    public static function payoutDetails(TempleSettlement $settlement): ?array
    {
        if (blank($settlement->payout_to)) {
            return null;
        }

        $decoded = json_decode((string) $settlement->payout_to, true);

        return is_array($decoded) ? $decoded : null;
    }
}
