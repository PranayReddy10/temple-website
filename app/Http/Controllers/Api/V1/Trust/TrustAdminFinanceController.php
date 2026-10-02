<?php

namespace App\Http\Controllers\Api\V1\Trust;

use App\Http\Controllers\Api\V1\Trust\Concerns\ScopesToTrustTemples;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PujaBooking;
use App\Models\Temple;
use App\Models\TempleSettlement;
use App\Support\DevotionalClock;
use App\Support\Finance\Settlements;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Settling with temples from the trust app: the same service as the admin
 * panel's Finance pages, so a settlement prepared in one can be marked paid
 * in the other. Only a super admin reaches these routes.
 */
class TrustAdminFinanceController extends Controller
{
    use ScopesToTrustTemples;

    /** Money across the platform, and every temple that is owed. */
    public function overview(Settlements $settlements): JsonResponse
    {
        $now = DevotionalClock::now();
        $cutoff = $settlements->defaultCutoff()->toDateString();

        $sum = fn ($query): array => (function ($row): array {
            return ['bookings' => (int) $row->n, 'amount_paise' => (int) $row->amount, 'amount' => TempleSettlement::rupees((int) $row->amount)];
        })($query->selectRaw('count(*) as n, coalesce(sum(amount_paise), 0) as amount')->first());

        // Everything paid and unsettled across sevas, tickets and hundi gifts,
        // advance ones included; "ahead" is the part dated after yesterday.
        $owed = [];
        foreach (Settlements::SOURCES as $key => [$model, $column]) {
            $rows = $model::query()->settleable()
                ->selectRaw("temple_id, count(*) as n, coalesce(sum(amount_paise), 0) as gross, coalesce(sum(case when {$column} > ? then amount_paise else 0 end), 0) as ahead", [$cutoff])
                ->groupBy('temple_id')
                ->get();
            foreach ($rows as $r) {
                $o = $owed[$r->temple_id] ?? ['n' => 0, 'gross' => 0, 'ahead' => 0, 'services' => 0, 'donations' => 0];
                $o['n'] += (int) $r->n;
                $o['gross'] += (int) $r->gross;
                $o['ahead'] += (int) $r->ahead;
                $o[$key === 'donations' ? 'donations' : 'services'] += (int) $r->gross;
                $owed[$r->temple_id] = $o;
            }
        }
        $owed = collect($owed);

        $pending = TempleSettlement::query()->pending()
            ->selectRaw('temple_id, count(*) as n, coalesce(sum(net_paise), 0) as net')
            ->groupBy('temple_id')
            ->get()
            ->keyBy('temple_id');

        $ids = $owed->keys()->merge($pending->keys())->unique()->all();

        $temples = Temple::query()->whereKey($ids)->with('payoutAccount')->orderBy('name')->get(['id', 'name', 'city'])
            ->map(function (Temple $t) use ($owed, $pending, $settlements): array {
                $o = $owed[$t->id] ?? ['n' => 0, 'gross' => 0, 'ahead' => 0, 'services' => 0, 'donations' => 0];
                $gross = $o['gross'];
                $percent = $settlements->feePercentFor($t);
                $fee = $settlements->feeFor($t, $o['services'], $o['donations']);

                return [
                    'id' => $t->id,
                    'name' => $t->name,
                    'city' => $t->city,
                    'ready_bookings' => $o['n'],
                    'ready_donations_paise' => $o['donations'],
                    'ready_gross_paise' => $gross,
                    'ahead_gross_paise' => $o['ahead'],
                    'fee_percent' => $percent,
                    'ready_net_paise' => $gross - $fee,
                    'ready_net' => TempleSettlement::rupees($gross - $fee),
                    'in_payout_net_paise' => (int) ($pending[$t->id]->net ?? 0),
                    'in_payout' => TempleSettlement::rupees((int) ($pending[$t->id]->net ?? 0)),
                    'payout_account' => $t->payoutAccount?->toPublicArray(),
                ];
            })->values();

        $readyNet = $temples->sum('ready_net_paise');
        $inPayout = (int) TempleSettlement::query()->pending()->sum('net_paise');

        return response()->json(['data' => [
            'cutoff' => $cutoff,
            'today' => $sum(PujaBooking::query()->paidFor()->whereDate('booked_for', $now->toDateString())),
            'collected_today' => (function () use ($now): array {
                $amount = (int) Payment::query()->paid()->whereIn('purpose', [Payment::PUJA_BOOKING, Payment::EVENT_TICKET, Payment::DONATION])
                    ->where('paid_at', '>=', $now->copy()->startOfDay()->utc())->sum('amount_paise');

                return ['amount_paise' => $amount, 'amount' => TempleSettlement::rupees($amount)];
            })(),
            'month' => $sum(PujaBooking::query()->paidFor()->whereDate('booked_for', '>=', $now->copy()->startOfMonth()->toDateString())->whereDate('booked_for', '<=', $now->copy()->endOfMonth()->toDateString())),
            'ready_net_paise' => $readyNet,
            'ready_net' => TempleSettlement::rupees($readyNet),
            'in_payout_net_paise' => $inPayout,
            'in_payout' => TempleSettlement::rupees($inPayout),
            'temples' => $temples,
        ]]);
    }

    public function settlements(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(array_keys(TempleSettlement::STATUSES))],
            'temple_id' => ['nullable', 'integer'],
        ]);

        $rows = TempleSettlement::query()
            ->with('temple:id,name,city')
            ->when($validated['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($validated['temple_id'] ?? null, fn ($q, $t) => $q->where('temple_id', $t))
            ->latest('id')
            ->limit(200)
            ->get();

        return response()->json(['data' => $rows->map(fn (TempleSettlement $s): array => $this->row($s))->values()]);
    }

    public function store(Request $request, Settlements $settlements, int $temple): JsonResponse
    {
        $record = $this->managedTemple($request, $temple);

        $validated = $request->validate([
            'up_to' => ['nullable', 'date_format:Y-m-d'],
            // Everything unsettled, advance bookings included.
            'all' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $upTo = ($validated['all'] ?? false)
            ? ($settlements->latestUnsettledDay($record) ?? DevotionalClock::now())
            : ($validated['up_to'] ?? null);

        $row = $settlements->create($record, $upTo, $this->trustUser($request), $validated['note'] ?? null);

        return response()->json(['data' => $this->row($row->load('temple:id,name,city'))], 201);
    }

    public function paid(Request $request, Settlements $settlements, int $settlement): JsonResponse
    {
        $validated = $request->validate([
            'method' => ['required', Rule::in(array_keys(TempleSettlement::METHODS))],
            'transaction_ref' => ['nullable', 'string', 'max:80'],
            'paid_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $row = $settlements->markPaid(
            TempleSettlement::query()->findOrFail($settlement),
            $validated['method'],
            $validated['transaction_ref'] ?? null,
            $this->trustUser($request),
            $validated['paid_at'] ?? null,
            $validated['note'] ?? null,
        );

        return response()->json(['data' => $this->row($row->load('temple:id,name,city'))]);
    }

    public function cancel(Request $request, Settlements $settlements, int $settlement): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $row = $settlements->cancel(TempleSettlement::query()->findOrFail($settlement), $validated['reason']);

        return response()->json(['data' => $this->row($row->load('temple:id,name,city'))]);
    }

    public function verifyPayoutAccount(Request $request, int $temple): JsonResponse
    {
        $record = $this->managedTemple($request, $temple);
        $account = $record->payoutAccount;

        abort_if($account === null || ! $account->isComplete(), 422, 'This temple has not added its payout details yet.');

        $account->forceFill(['verified_at' => now(), 'verified_by' => $this->trustUser($request)->getKey()])->saveQuietly();

        return response()->json(['data' => $account->refresh()->toPublicArray()]);
    }

    /** A settlement with the full payout details, for the person paying it. */
    protected function row(TempleSettlement $s): array
    {
        return $s->toPublicArray() + ['payout_to' => Settlements::payoutDetails($s)];
    }
}
