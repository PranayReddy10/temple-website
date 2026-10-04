<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step in a temple's payment verification. Written once, never edited.
 */
class TemplePayoutVerificationEvent extends Model
{
    public const UPDATED_AT = null;

    public const LABELS = [
        'submitted' => 'Documents sent',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'bank_changed' => 'Bank details changed',
        'approval_removed' => 'Approval removed',
    ];

    protected $fillable = ['temple_payout_account_id', 'temple_id', 'event', 'reason', 'user_id', 'snapshot'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'created_at' => 'datetime'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(TemplePayoutAccount::class, 'temple_payout_account_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function label(): string
    {
        return self::LABELS[$this->event] ?? ucfirst(str_replace('_', ' ', $this->event));
    }

    /** The path of a document as it was at this step, if one was kept. */
    public function documentPath(string $key): ?string
    {
        return $this->snapshot['documents'][$key] ?? null;
    }

    public function documentDisk(): string
    {
        return $this->snapshot['disk'] ?? 'local';
    }
}
