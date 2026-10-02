<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Where a temple's settlements are paid: a bank account, a UPI id, or both.
 *
 * The temple's owner keeps it up to date; staff verify it before paying, and
 * any change by the temple clears that verification. The account number is
 * encrypted at rest and only ever shown in full to staff.
 */
class TemplePayoutAccount extends Model
{
    protected $fillable = [
        'temple_id', 'account_name', 'account_number', 'ifsc', 'bank_name', 'upi_id',
        'platform_fee_percent', 'updated_by',
        'kyc_name', 'aadhaar_number', 'temple_proof_kind',
    ];

    /** Shown wherever money collection is refused for want of approval. */
    public const NOT_APPROVED_MESSAGE = 'To take money in the app, the temple\'s owner first adds the bank account and the verification documents (Aadhaar, temple proof, photo) in Finance → Verification, and our team approves them.';

    /** The documents asked for, by field: [column, label]. */
    public const DOCUMENTS = [
        'aadhaar_front' => ['aadhaar_front_path', 'Aadhaar card (front)'],
        'aadhaar_back' => ['aadhaar_back_path', 'Aadhaar card (back)'],
        'temple_proof' => ['temple_proof_path', 'Proof of the temple'],
        'person_photo' => ['person_photo_path', 'Photo of the person'],
    ];

    /** What can prove the person represents the temple. */
    public const PROOF_KINDS = [
        'trust_registration' => 'Trust / society registration certificate',
        'endowments_letter' => 'Endowments department order or letter',
        'committee_letter' => 'Temple committee resolution or letter',
        'property_document' => 'Temple land or property document',
        'other' => 'Other official document',
    ];

    protected function casts(): array
    {
        return [
            'account_number' => 'encrypted',
            'platform_fee_percent' => 'decimal:2',
            'verified_at' => 'datetime',
            'aadhaar_number' => 'encrypted',
            'kyc_submitted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (TemplePayoutAccount $account): void {
            $digits = preg_replace('/\D/', '', (string) $account->account_number);
            $account->account_last4 = $digits === '' ? null : substr($digits, -4);

            $aadhaar = preg_replace('/\D/', '', (string) $account->aadhaar_number);
            $account->aadhaar_last4 = $aadhaar === '' ? null : substr($aadhaar, -4);

            // The details money is sent to, or who is behind them, changed:
            // somebody checks them again before any more money is taken.
            if ($account->exists && $account->isDirty([
                'account_name', 'account_number', 'ifsc', 'upi_id',
                'kyc_name', 'aadhaar_number', 'aadhaar_front_path', 'aadhaar_back_path', 'temple_proof_path', 'person_photo_path',
            ])) {
                $account->verified_at = null;
                $account->verified_by = null;
            }
        });
    }

    public function temple(): BelongsTo
    {
        return $this->belongsTo(Temple::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function isComplete(): bool
    {
        return (filled($this->account_number) && filled($this->ifsc) && filled($this->account_name))
            || filled($this->upi_id);
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    /** Every document is in, with the name and Aadhaar number. */
    public function hasKyc(): bool
    {
        return filled($this->kyc_name) && filled($this->aadhaar_last4)
            && collect(self::DOCUMENTS)->every(fn (array $d): bool => filled($this->{$d[0]}));
    }

    /**
     * Money may be taken for this temple: bank details, the person's
     * documents, and staff have approved both.
     */
    public function canReceiveMoney(): bool
    {
        return $this->isComplete() && $this->hasKyc() && $this->isVerified();
    }

    /** missing · pending · approved · rejected */
    public function kycStatus(): string
    {
        return match (true) {
            $this->canReceiveMoney() => 'approved',
            ! $this->isComplete() || ! $this->hasKyc() => filled($this->rejection_reason) ? 'rejected' : 'missing',
            filled($this->rejection_reason) => 'rejected',
            default => 'pending',
        };
    }

    public function kycStatusLabel(): string
    {
        return match ($this->kycStatus()) {
            'approved' => 'Approved — payments are on',
            'pending' => 'Waiting for the team to check',
            'rejected' => 'Not approved',
            default => 'Details or documents missing',
        };
    }

    public function maskedAadhaar(): ?string
    {
        return $this->aadhaar_last4 === null ? null : 'XXXX XXXX '.$this->aadhaar_last4;
    }

    public function kycDisk(): string
    {
        return $this->kyc_disk ?: 'local';
    }

    /** Removes the stored documents, for when the account is deleted. */
    public function deleteDocuments(): void
    {
        foreach (self::DOCUMENTS as [$column]) {
            if (filled($this->{$column})) {
                Storage::disk($this->kycDisk())->delete($this->{$column});
            }
        }
    }

    public function maskedAccountNumber(): ?string
    {
        return $this->account_last4 === null ? null : 'XXXX'.$this->account_last4;
    }

    /** One line for a list or a receipt, without the full account number. */
    public function summary(): string
    {
        return collect([
            $this->account_name,
            $this->bank_name,
            $this->maskedAccountNumber(),
            $this->ifsc,
            $this->upi_id ? 'UPI '.$this->upi_id : null,
        ])->filter()->implode(' · ');
    }

    /** As the temple's team sees it: never the full number. */
    public function toPublicArray(): array
    {
        return [
            'account_name' => $this->account_name,
            'account_number_masked' => $this->maskedAccountNumber(),
            'ifsc' => $this->ifsc,
            'bank_name' => $this->bank_name,
            'upi_id' => $this->upi_id,
            'is_complete' => $this->isComplete(),
            'is_verified' => $this->isVerified(),
            'verified_at' => $this->verified_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'can_receive_money' => $this->canReceiveMoney(),
            'kyc' => [
                'status' => $this->kycStatus(),
                'label' => $this->kycStatusLabel(),
                'name' => $this->kyc_name,
                'aadhaar_masked' => $this->maskedAadhaar(),
                'temple_proof_kind' => $this->temple_proof_kind,
                'documents' => collect(self::DOCUMENTS)->map(fn (array $d): bool => filled($this->{$d[0]}))->all(),
                'submitted_at' => $this->kyc_submitted_at?->toIso8601String(),
                'rejection_reason' => $this->rejection_reason,
            ],
        ];
    }
}
