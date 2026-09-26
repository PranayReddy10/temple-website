<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a donor says they sent to a drive's UPI ID.
 *
 * The platform never sees the money, so this is the donor's word until the
 * organiser confirms it arrived. Only confirmed amounts count.
 */
class SevaDriveDonation extends Model
{
    protected $fillable = ['devotee_id', 'amount', 'upi_ref', 'message', 'is_anonymous'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'is_anonymous' => 'boolean',
            'confirmed_at' => 'datetime',
        ];
    }

    public function drive(): BelongsTo
    {
        return $this->belongsTo(SevaDrive::class, 'seva_drive_id');
    }

    public function devotee(): BelongsTo
    {
        return $this->belongsTo(Devotee::class);
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }

    /** The name shown to others: nobody's, when they asked for that. */
    public function donorName(): string
    {
        return $this->is_anonymous || $this->devotee === null
            ? 'A devotee'
            : $this->devotee->name;
    }
}
