<?php

namespace App\Http\Controllers\Api\V1\Trust;

use App\Http\Controllers\Api\V1\Trust\Concerns\ScopesToTrustTemples;
use App\Http\Controllers\Controller;
use App\Models\Temple;
use App\Models\TemplePayoutAccount;
use App\Models\TempleSettlement;
use App\Models\TempleUser;
use App\Support\DevotionalClock;
use App\Support\Finance\Settlements;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A temple's money, as its own team sees it: what devotees booked and paid
 * today and this month, what the platform holds for the temple, what has
 * been paid out and with which bank reference, and where it is paid.
 *
 * Only the temple's owner (or a super admin) may change the payout account,
 * and a change waits for staff to verify it again before money follows it.
 */
class TrustFinanceController extends Controller
{
    use ScopesToTrustTemples;

    public function show(Request $request, Settlements $settlements, int $temple): JsonResponse
    {
        $record = $this->managedTemple($request, $temple);
        $record->load('payoutAccount');

        $validated = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $now = DevotionalClock::now();
        $day = $validated['date'] ?? $now->toDateString();

        return response()->json(['data' => [
            'today' => $settlements->day($record, $now->toDateString()),
            'day' => $settlements->day($record, $day),
            'month' => $settlements->period($record, $now->copy()->startOfMonth(), $now->copy()->endOfMonth()),
            'balance' => $settlements->balance($record),
            'payout_account' => $record->payoutAccount?->toPublicArray(),
            'can_edit_payout_account' => $this->canEditPayout($request, $record),
            'recent_settlements' => $record->settlements()->latest('id')->limit(5)->get()
                ->map(fn (TempleSettlement $s): array => $s->toPublicArray())->values(),
        ]]);
    }

    public function settlements(Request $request, int $temple): JsonResponse
    {
        $record = $this->managedTemple($request, $temple);

        $rows = $record->settlements()->latest('id')->limit(200)->get();

        return response()->json(['data' => $rows->map(fn (TempleSettlement $s): array => $s->toPublicArray())->values()]);
    }

    public function settlement(Request $request, int $temple, int $settlement): JsonResponse
    {
        $record = $this->managedTemple($request, $temple);

        /** @var TempleSettlement $row */
        $row = $record->settlements()->with(['bookings' => fn ($q) => $q->with('puja:id,name')->orderBy('booked_for')])->findOrFail($settlement);

        return response()->json(['data' => $row->toPublicArray(withBookings: true)]);
    }

    public function updatePayoutAccount(Request $request, int $temple): JsonResponse
    {
        $record = $this->managedTemple($request, $temple);

        abort_unless($this->canEditPayout($request, $record), 403, 'Only the temple\'s owner can change where settlements are paid.');

        $validated = $request->validate([
            'account_name' => ['nullable', 'required_with:account_number', 'string', 'max:120'],
            'account_number' => ['nullable', 'regex:/^[0-9]{6,20}$/'],
            'ifsc' => ['nullable', 'required_with:account_number', 'regex:/^[A-Za-z]{4}0[A-Za-z0-9]{6}$/'],
            'bank_name' => ['nullable', 'string', 'max:120'],
            'upi_id' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9.\-_]{2,256}@[A-Za-z]{2,64}$/'],
        ], [
            'account_number.regex' => 'Enter the account number as digits only.',
            'ifsc.regex' => 'An IFSC is 11 characters, like SBIN0001234.',
            'upi_id.regex' => 'A UPI id looks like name@bank.',
        ]);

        /** @var TemplePayoutAccount $account */
        $account = $record->payoutAccount()->firstOrNew();
        $account->fill([
            'account_name' => $validated['account_name'] ?? null,
            'ifsc' => isset($validated['ifsc']) ? strtoupper($validated['ifsc']) : null,
            'bank_name' => $validated['bank_name'] ?? null,
            'upi_id' => $validated['upi_id'] ?? null,
            'updated_by' => $this->trustUser($request)->getKey(),
        ]);

        // A blank number keeps the one on file: the app never receives it,
        // so it cannot send it back. Clearing the name clears the account.
        if (filled($validated['account_number'] ?? null)) {
            $account->account_number = $validated['account_number'];
        } elseif (blank($validated['account_name'] ?? null)) {
            $account->account_number = null;
        }

        if (! $account->isComplete()) {
            return response()->json([
                'message' => 'Enter a bank account (holder name, number and IFSC) or a UPI id.',
                'errors' => ['account_number' => ['Enter a bank account (holder name, number and IFSC) or a UPI id.']],
            ], 422);
        }

        $account->save();

        return response()->json(['data' => $account->refresh()->toPublicArray()]);
    }

    protected function canEditPayout(Request $request, Temple $temple): bool
    {
        $user = $this->trustUser($request);

        return $user->isSuperAdmin() || TempleUser::query()
            ->approved()
            ->where('temple_id', $temple->getKey())
            ->where('user_id', $user->getKey())
            ->where('role', 'owner')
            ->exists();
    }
}
