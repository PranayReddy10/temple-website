<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
    ];

    protected function casts(): array
    {
        return [
            'account_number' => 'encrypted',
            'platform_fee_percent' => 'decimal:2',
            'verified_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (TemplePayoutAccount $account): void {
            $digits = preg_replace('/\D/', '', (string) $account->account_number);
            $account->account_last4 = $digits === '' ? null : substr($digits, -4);

            // The details money is sent to changed: somebody checks them again.
            if ($account->exists && $account->isDirty(['account_name', 'account_number', 'ifsc', 'upi_id'])) {
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
        ];
    }
}
