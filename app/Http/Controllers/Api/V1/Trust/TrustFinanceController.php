<?php

namespace App\Http\Controllers\Api\V1\Trust;

use App\Http\Controllers\Api\V1\Trust\Concerns\ScopesToTrustTemples;
use App\Http\Controllers\Controller;
use App\Models\Temple;
use App\Models\TempleDonation;
use App\Models\TemplePayoutAccount;
use App\Models\TempleSettlement;
use App\Models\TempleUser;
use App\Support\DevotionalClock;
use App\Support\Finance\Settlements;
use App\Support\MediaStorage;
use App\Support\UploadRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
            'accepts_donations' => (bool) $record->accepts_donations,
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
        $row = $record->settlements()->with([
            'bookings' => fn ($q) => $q->with('puja:id,name')->orderBy('booked_for'),
            'tickets' => fn ($q) => $q->with('event:id,title')->orderBy('occurs_on'),
            'donations' => fn ($q) => $q->with('devotee:id,name')->orderBy('paid_on'),
        ])->findOrFail($settlement);

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

        $wasApproved = $account->exists && $account->isVerified();
        $account->save();

        if ($wasApproved && ! $account->isVerified()) {
            $account->recordEvent('bank_changed', 'Changed by the temple; payments paused until approved again.', $this->trustUser($request)->getKey());
        }

        return response()->json(['data' => $account->refresh()->toPublicArray()]);
    }

    /**
     * The owner's proof, before the temple may take money in the app: the
     * name and Aadhaar of the person, the Aadhaar card, a document showing
     * the temple is theirs to represent, and a photo of them. Staff approve
     * it with the bank details; any change sends it back for checking.
     */
    public function submitKyc(Request $request, int $temple): JsonResponse
    {
        $record = $this->managedTemple($request, $temple);

        abort_unless($this->canEditPayout($request, $record), 403, 'Only the temple\'s owner can send the verification documents.');

        /** @var TemplePayoutAccount $account */
        $account = $record->payoutAccount()->firstOrNew();
        $has = fn (string $column): bool => filled($account->{$column});
        $doc = fn (string $column, bool $imageOnly = false): array => [
            $has($column) ? 'nullable' : 'required',
            'file',
            'mimes:'.($imageOnly ? UploadRules::mimesRuleFor('temple_photo') : UploadRules::mimesRuleFor('kyc_document')),
            'max:'.UploadRules::maxKbFor('kyc_document'),
        ];

        // "2345 6789 0123" as printed on the card.
        if ($request->filled('aadhaar_number')) {
            $request->merge(['aadhaar_number' => preg_replace('/\s+/', '', (string) $request->input('aadhaar_number'))]);
        }

        $validated = $request->validate([
            'kyc_name' => [$has('kyc_name') ? 'nullable' : 'required', 'string', 'min:3', 'max:120'],
            // Twelve digits, never starting with 0 or 1.
            'aadhaar_number' => [$has('aadhaar_number') ? 'nullable' : 'required', 'regex:/^[2-9][0-9]{11}$/'],
            'temple_proof_kind' => [$has('temple_proof_kind') ? 'nullable' : 'required', Rule::in(array_keys(TemplePayoutAccount::PROOF_KINDS))],
            'aadhaar_front' => $doc('aadhaar_front_path'),
            'aadhaar_back' => $doc('aadhaar_back_path'),
            'temple_proof' => $doc('temple_proof_path'),
            'person_photo' => $doc('person_photo_path', imageOnly: true),
        ], [
            'aadhaar_number.regex' => 'An Aadhaar number is 12 digits.',
            'kyc_name.required' => 'Enter the name as it is on the Aadhaar card.',
        ]);

        // Documents go where uploads go (privately on Spaces when it is on).
        // Ones already sent elsewhere are carried over, so one account's
        // documents always sit on one disk.
        $disk = MediaStorage::privateDisk();
        if (filled($account->kyc_disk) && $account->kyc_disk !== $disk) {
            foreach (TemplePayoutAccount::DOCUMENTS as $field => [$column]) {
                if (filled($account->{$column}) && ! $request->hasFile($field) && Storage::disk($account->kyc_disk)->exists($account->{$column})) {
                    Storage::disk($disk)->writeStream($account->{$column}, Storage::disk($account->kyc_disk)->readStream($account->{$column}), ['visibility' => 'private']);
                }
            }
        }
        $account->kyc_disk = $disk;

        foreach (TemplePayoutAccount::DOCUMENTS as $field => [$column]) {
            if ($request->hasFile($field)) {
                $account->{$column} = $request->file($field)->store('kyc/'.$record->getKey(), ['disk' => $disk, 'visibility' => 'private']);
            }
        }

        foreach (['kyc_name', 'aadhaar_number', 'temple_proof_kind'] as $key) {
            if (filled($validated[$key] ?? null)) {
                $account->{$key} = $validated[$key];
            }
        }

        $account->forceFill([
            'temple_id' => $record->getKey(),
            'kyc_submitted_at' => now(),
            'rejection_reason' => null,
            'rejected_at' => null,
            'updated_by' => $this->trustUser($request)->getKey(),
            // New documents are always checked again.
            'verified_at' => null,
            'verified_by' => null,
        ])->save();

        // The replaced files are kept: the history shows what each
        // submission (and each rejection) was about.
        $account->refresh()->recordEvent('submitted', null, $this->trustUser($request)->getKey());

        return response()->json(['data' => $account->toPublicArray()]);
    }

    /** Online hundi: every gift, newest first, with today's and the month's totals. */
    public function donations(Request $request, int $temple): JsonResponse
    {
        $record = $this->managedTemple($request, $temple);
        $now = DevotionalClock::now();

        $validated = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);

        $rows = $record->donations()
            ->whereIn('status', [TempleDonation::PAID, TempleDonation::REFUNDED])
            ->with('devotee:id,name')
            ->when($validated['date'] ?? null, fn ($q, $d) => $q->whereDate('paid_on', $d))
            ->latest('paid_at')
            ->limit(200)
            ->get();

        $sum = fn ($q) => ['count' => (int) (clone $q)->count(), 'amount_paise' => (int) (clone $q)->sum('amount_paise')];
        $paid = fn () => $record->donations()->paid();

        return response()->json(['data' => [
            'accepts_donations' => (bool) $record->accepts_donations,
            'can_change' => $this->canEditPayout($request, $record),
            'today' => $sum($paid()->whereDate('paid_on', $now->toDateString())),
            'month' => $sum($paid()->whereDate('paid_on', '>=', $now->copy()->startOfMonth()->toDateString())),
            'total' => $sum($paid()),
            'items' => $rows->map(fn (TempleDonation $d): array => $d->toTempleArray())->values(),
        ]]);
    }

    /** The owner switches the online hundi on or off. */
    public function donationSettings(Request $request, int $temple): JsonResponse
    {
        $record = $this->managedTemple($request, $temple);

        abort_unless($this->canEditPayout($request, $record), 403, 'Only the temple\'s owner can switch the online hundi on or off.');

        $validated = $request->validate(['accepts_donations' => ['required', 'boolean']]);

        if ($validated['accepts_donations'] && ! $record->canCollectPayments()) {
            throw ValidationException::withMessages(['accepts_donations' => TemplePayoutAccount::NOT_APPROVED_MESSAGE]);
        }

        $record->forceFill(['accepts_donations' => $validated['accepts_donations']])->save();

        return response()->json(['data' => ['accepts_donations' => (bool) $record->accepts_donations]]);
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
