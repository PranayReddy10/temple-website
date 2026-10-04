<?php

namespace App\Support\Finance;

use App\Models\EventRegistration;
use App\Models\PujaBooking;
use App\Models\Setting;
use App\Models\Temple;
use App\Models\TempleDonation;
use App\Models\TempleSettlement;
use App\Models\User;
use App\Support\DevotionalClock;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What the platform owes each temple, and paying it.
 *
 * Three kinds of money come in through the platform's gateway for a temple:
 * seva bookings, event tickets and online hundi gifts. Each becomes the
 * temple's once paid; a settlement gathers all three up to a day (the
 * booking's or ticket's day, the day a gift was made), keeps the platform
 * fee (one rate for sevas and tickets, its own for gifts), and is marked paid
 * once the transfer is made. Preparing a settlement locks its items to it;
 * cancelling releases them into the next one. Nothing here moves money.
 *
 * The admin panel and the trust app both go through here.
 */
class Settlements
{
    /** The three sources: model, the column that dates an item, and its key in the breakdown. */
    public const SOURCES = [
        'bookings' => [PujaBooking::class, 'booked_for'],
        'tickets' => [EventRegistration::class, 'occurs_on'],
        'donations' => [TempleDonation::class, 'paid_on'],
    ];

    /** Default fee on sevas and tickets, in percent, when a temple has none of its own. */
    public function defaultFeePercent(): float
    {
        return max(0.0, min(100.0, (float) Setting::get('finance_platform_fee_percent', 0)));
    }

    /** Fee on hundi gifts, in percent: its own setting, usually lower or none. */
    public function donationFeePercent(): float
    {
        return max(0.0, min(100.0, (float) Setting::get('finance_donation_fee_percent', 0)));
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

    /** The fee on a mix: sevas and tickets at the temple's rate, gifts at the donation rate. */
    public function feeFor(Temple $temple, int $servicesPaise, int $donationsPaise): int
    {
        return self::feeOf($servicesPaise, $this->feePercentFor($temple)) + self::feeOf($donationsPaise, $this->donationFeePercent());
    }

    /** The last day a settlement made now covers by default: yesterday. */
    public function defaultCutoff(): CarbonInterface
    {
        return DevotionalClock::now()->subDay()->startOfDay();
    }

    /** Unsettled paid items of one kind for a temple, dated on or before $upTo. */
    public function sourceQuery(string $source, Temple|int $temple, CarbonInterface|string|null $upTo = null, CarbonInterface|string|null $after = null): Builder
    {
        [$model, $column] = self::SOURCES[$source];
        $id = $temple instanceof Temple ? $temple->getKey() : $temple;
        $date = fn ($d) => $d instanceof CarbonInterface ? $d->toDateString() : $d;

        return $model::query()
            ->where('temple_id', $id)
            ->settleable()
            ->when($upTo !== null, fn (Builder $q) => $q->whereDate($column, '<=', $date($upTo)))
            ->when($after !== null, fn (Builder $q) => $q->whereDate($column, '>', $date($after)));
    }

    /** Kept for callers that only ever meant seva bookings. */
    public function settleableQuery(Temple|int $temple, CarbonInterface|string|null $upTo = null): Builder
    {
        return $this->sourceQuery('bookings', $temple, $upTo);
    }

    /** The last day with anything unsettled, so a settlement can take everything. */
    public function latestUnsettledDay(Temple|int $temple): ?CarbonInterface
    {
        $days = collect(self::SOURCES)->map(fn ($s, $key) => $this->sourceQuery($key, $temple)->max($s[1]))->filter();

        return $days->isEmpty() ? null : Carbon::parse($days->max())->startOfDay();
    }

    /**
     * Count and gross of each kind for a temple, up to and/or after a day.
     *
     * @return array{bookings: array{count: int, paise: int}, tickets: array{count: int, paise: int}, donations: array{count: int, paise: int}}
     */
    public function totals(Temple|int $temple, CarbonInterface|string|null $upTo = null, CarbonInterface|string|null $after = null): array
    {
        $out = [];
        foreach (array_keys(self::SOURCES) as $key) {
            $row = $this->sourceQuery($key, $temple, $upTo, $after)->selectRaw('count(*) as n, coalesce(sum(amount_paise), 0) as gross')->first();
            $out[$key] = ['count' => (int) $row->n, 'paise' => (int) $row->gross];
        }

        return $out;
    }

    /**
     * A temple's position: what is ready to settle (up to yesterday), what
     * is paid for days still ahead, what is being paid out, what has been paid.
     */
    public function balance(Temple $temple): array
    {
        $cutoff = $this->defaultCutoff()->toDateString();
        $ready = $this->totals($temple, $cutoff);
        $ahead = $this->totals($temple, null, $cutoff);

        $readyServices = $ready['bookings']['paise'] + $ready['tickets']['paise'];
        $readyGross = $readyServices + $ready['donations']['paise'];
        $readyFee = $this->feeFor($temple, $readyServices, $ready['donations']['paise']);

        $pending = $temple->settlements()->pending()->selectRaw('count(*) as n, coalesce(sum(net_paise), 0) as net')->first();
        $paid = $temple->settlements()->paid()->selectRaw('count(*) as n, coalesce(sum(net_paise), 0) as net, coalesce(sum(fee_paise), 0) as fee, max(paid_at) as last_paid_at')->first();

        return [
            'fee_percent' => $this->feePercentFor($temple),
            'donation_fee_percent' => $this->donationFeePercent(),
            'cutoff' => $cutoff,
            'ready' => [
                'bookings' => $ready['bookings']['count'],
                'tickets' => $ready['tickets']['count'],
                'donations' => $ready['donations']['count'],
                'bookings_paise' => $ready['bookings']['paise'],
                'tickets_paise' => $ready['tickets']['paise'],
                'donations_paise' => $ready['donations']['paise'],
                'gross_paise' => $readyGross,
                'fee_paise' => $readyFee,
                'net_paise' => $readyGross - $readyFee,
            ],
            // Paid for days still to come (and gifts made today).
            'upcoming' => [
                'bookings' => $ahead['bookings']['count'] + $ahead['tickets']['count'] + $ahead['donations']['count'],
                'gross_paise' => $ahead['bookings']['paise'] + $ahead['tickets']['paise'] + $ahead['donations']['paise'],
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
     * One day at the temple, for the counter and the office: sevas booked
     * for the day and what they paid, who has come, event tickets for the
     * day, and the hundi gifts made that day.
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

        $tickets = $temple->eventRegistrations()->live()->whereDate('occurs_on', $date)
            ->selectRaw('count(*) as n, coalesce(sum(people), 0) as people, coalesce(sum(amount_paise), 0) as amount')->first();
        $gifts = $temple->donations()->paid()->whereDate('paid_on', $date)
            ->selectRaw('count(*) as n, coalesce(sum(amount_paise), 0) as amount')->first();

        $liveBookings = $n('confirmed', 'n') + $n('verified', 'n');
        $liveAmount = $n('confirmed', 'amount') + $n('verified', 'amount');
        $total = $liveAmount + (int) $tickets->amount + (int) $gifts->amount;

        return [
            'date' => $date,
            // Seva bookings, as before.
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
            'tickets' => [
                'count' => (int) $tickets->n,
                'people' => (int) $tickets->people,
                'amount_paise' => (int) $tickets->amount,
                'amount' => TempleSettlement::rupees((int) $tickets->amount),
            ],
            'donations' => [
                'count' => (int) $gifts->n,
                'amount_paise' => (int) $gifts->amount,
                'amount' => TempleSettlement::rupees((int) $gifts->amount),
            ],
            'total_paise' => $total,
            'total' => TempleSettlement::rupees($total),
        ];
    }

    /** Sevas, tickets and gifts over a range of days. */
    public function period(Temple $temple, CarbonInterface $from, CarbonInterface $to): array
    {
        [$f, $t] = [$from->toDateString(), $to->toDateString()];

        $row = $temple->pujaBookings()->live()->whereDate('booked_for', '>=', $f)->whereDate('booked_for', '<=', $t)
            ->selectRaw('count(*) as n, coalesce(sum(people), 0) as people, coalesce(sum(amount_paise), 0) as amount')->first();
        $tickets = $temple->eventRegistrations()->live()->whereDate('occurs_on', '>=', $f)->whereDate('occurs_on', '<=', $t)
            ->selectRaw('count(*) as n, coalesce(sum(people), 0) as people, coalesce(sum(amount_paise), 0) as amount')->first();
        $gifts = $temple->donations()->paid()->whereDate('paid_on', '>=', $f)->whereDate('paid_on', '<=', $t)
            ->selectRaw('count(*) as n, coalesce(sum(amount_paise), 0) as amount')->first();

        $total = (int) $row->amount + (int) $tickets->amount + (int) $gifts->amount;

        return [
            'from' => $f,
            'to' => $t,
            'bookings' => (int) $row->n,
            'people' => (int) $row->people,
            'amount_paise' => (int) $row->amount,
            'amount' => TempleSettlement::rupees((int) $row->amount),
            'tickets' => ['count' => (int) $tickets->n, 'people' => (int) $tickets->people, 'amount_paise' => (int) $tickets->amount, 'amount' => TempleSettlement::rupees((int) $tickets->amount)],
            'donations' => ['count' => (int) $gifts->n, 'amount_paise' => (int) $gifts->amount, 'amount' => TempleSettlement::rupees((int) $gifts->amount)],
            'total_paise' => $total,
            'total' => TempleSettlement::rupees($total),
        ];
    }

    /**
     * Gathers everything paid and unsettled up to $upTo into a settlement.
     *
     * @throws ValidationException when there is nothing to settle
     */
    public function create(Temple $temple, CarbonInterface|string|null $upTo, ?User $by, ?string $note = null): TempleSettlement
    {
        $cutoff = $upTo === null
            ? $this->defaultCutoff()
            : ($upTo instanceof CarbonInterface ? $upTo : Carbon::parse($upTo));

        return DB::transaction(function () use ($temple, $cutoff, $by, $note): TempleSettlement {
            // Locked, so two people settling at once cannot take the same item.
            $items = [];
            foreach (self::SOURCES as $key => [, $column]) {
                $items[$key] = $this->sourceQuery($key, $temple, $cutoff)->lockForUpdate()->get(['id', 'amount_paise', $column]);
            }

            if (collect($items)->every(fn ($c) => $c->isEmpty())) {
                throw ValidationException::withMessages(['up_to' => 'Nothing to settle: no paid bookings, tickets or hundi gifts up to '.$cutoff->format('d M Y').' that are not already settled.']);
            }

            $paise = collect($items)->map(fn ($c) => (int) $c->sum('amount_paise'));
            $services = $paise['bookings'] + $paise['tickets'];
            $gross = $services + $paise['donations'];
            $fee = $this->feeFor($temple, $services, $paise['donations']);
            $from = collect(self::SOURCES)->map(fn ($s, $key) => $items[$key]->min($s[1]))->filter()->min();
            $account = $temple->payoutAccount;

            $settlement = $temple->settlements()->create([
                'period_from' => $from,
                'period_to' => $cutoff->toDateString(),
                'bookings_count' => $items['bookings']->count(),
                'bookings_paise' => $paise['bookings'],
                'tickets_count' => $items['tickets']->count(),
                'tickets_paise' => $paise['tickets'],
                'donations_count' => $items['donations']->count(),
                'donations_paise' => $paise['donations'],
                'gross_paise' => $gross,
                'fee_percent' => $this->feePercentFor($temple),
                'donation_fee_percent' => $this->donationFeePercent(),
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

            foreach (self::SOURCES as $key => [$model]) {
                if ($items[$key]->isNotEmpty()) {
                    $model::query()->whereKey($items[$key]->modelKeys())->update(['settlement_id' => $settlement->getKey()]);
                }
            }

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

    /** Releases its items, to be settled again in the next one. */
    public function cancel(TempleSettlement $settlement, ?string $reason = null): TempleSettlement
    {
        if (! $settlement->isPending()) {
            throw ValidationException::withMessages(['status' => 'A paid settlement cannot be cancelled.']);
        }

        return DB::transaction(function () use ($settlement, $reason): TempleSettlement {
            foreach (self::SOURCES as [$model]) {
                $model::query()->where('settlement_id', $settlement->getKey())->update(['settlement_id' => null]);
            }
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
