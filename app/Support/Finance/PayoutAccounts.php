<?php

namespace App\Support\Finance;

use App\Models\Temple;
use App\Models\TemplePayoutAccount;
use App\Models\User;
use App\Support\MediaStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Where a temple is paid, and the owner's proof that it may take money:
 * the same rules whether the owner uses the trust app or the temple portal.
 * Any change waits for staff to approve it again before money follows it.
 */
final class PayoutAccounts
{
    /**
     * The bank account and/or UPI id. A blank account number keeps the one
     * on file (it is never shown back); clearing the holder's name clears it.
     *
     * @param  array{account_name?: ?string, account_number?: ?string, ifsc?: ?string, bank_name?: ?string, upi_id?: ?string}  $data
     */
    public function updateBank(Temple $temple, array $data, User $by): TemplePayoutAccount
    {
        /** @var TemplePayoutAccount $account */
        $account = $temple->payoutAccount()->firstOrNew();
        $account->fill([
            'account_name' => ($data['account_name'] ?? null) ?: null,
            'ifsc' => filled($data['ifsc'] ?? null) ? strtoupper((string) $data['ifsc']) : null,
            'bank_name' => ($data['bank_name'] ?? null) ?: null,
            'upi_id' => ($data['upi_id'] ?? null) ?: null,
            'updated_by' => $by->getKey(),
        ]);

        if (filled($data['account_number'] ?? null)) {
            $account->account_number = (string) $data['account_number'];
        } elseif (blank($data['account_name'] ?? null)) {
            $account->account_number = null;
        }

        if (! $account->isComplete()) {
            throw ValidationException::withMessages(['account_number' => 'Enter a bank account (holder name, number and IFSC) or a UPI id.']);
        }

        $wasApproved = $account->exists && $account->isVerified();
        $account->save();

        if ($wasApproved && ! $account->isVerified()) {
            $account->recordEvent('bank_changed', 'Changed by the temple; payments paused until approved again.', $by->getKey());
        }

        return $account->refresh();
    }

    /**
     * The owner's documents: name and Aadhaar, the card's two sides, proof
     * the temple is theirs to represent, and their photo. Kept privately;
     * new documents are always checked again.
     *
     * @param  array{kyc_name?: ?string, aadhaar_number?: ?string, temple_proof_kind?: ?string}  $data
     * @param  array<string, UploadedFile>  $files  keyed as TemplePayoutAccount::DOCUMENTS
     */
    public function submitKyc(Temple $temple, array $data, array $files, User $by): TemplePayoutAccount
    {
        /** @var TemplePayoutAccount $account */
        $account = $temple->payoutAccount()->firstOrNew();

        // Documents go where uploads go (privately on Spaces when it is on).
        // Ones already sent elsewhere are carried over, so one account's
        // documents always sit on one disk.
        $disk = MediaStorage::privateDisk();
        if (filled($account->kyc_disk) && $account->kyc_disk !== $disk) {
            foreach (TemplePayoutAccount::DOCUMENTS as $field => [$column]) {
                if (filled($account->{$column}) && ! isset($files[$field]) && Storage::disk($account->kyc_disk)->exists($account->{$column})) {
                    Storage::disk($disk)->writeStream($account->{$column}, Storage::disk($account->kyc_disk)->readStream($account->{$column}), ['visibility' => 'private']);
                }
            }
        }
        $account->kyc_disk = $disk;

        foreach (TemplePayoutAccount::DOCUMENTS as $field => [$column]) {
            if (isset($files[$field])) {
                $account->{$column} = $files[$field]->store('kyc/'.$temple->getKey(), ['disk' => $disk, 'visibility' => 'private']);
            }
        }

        foreach (['kyc_name', 'aadhaar_number', 'temple_proof_kind'] as $key) {
            if (filled($data[$key] ?? null)) {
                $account->{$key} = $key === 'aadhaar_number' ? preg_replace('/\s+/', '', (string) $data[$key]) : $data[$key];
            }
        }

        $account->forceFill([
            'temple_id' => $temple->getKey(),
            'kyc_submitted_at' => now(),
            'rejection_reason' => null,
            'rejected_at' => null,
            'updated_by' => $by->getKey(),
            'verified_at' => null,
            'verified_by' => null,
        ])->save();

        // The replaced files are kept: the history shows what each
        // submission (and each rejection) was about.
        $account->refresh()->recordEvent('submitted', null, $by->getKey());

        return $account;
    }
}
