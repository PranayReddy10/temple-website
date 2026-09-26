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
    protected $fillable = ['devotee_id', 'amount', 'upi_ref', 'payment_app', 'paid_on', 'message', 'is_anonymous'];

    /** How the donor paid, as they pick it in the app. */
    public const PAYMENT_APPS = [
        'phonepe' => 'PhonePe',
        'gpay' => 'Google Pay',
        'paytm' => 'Paytm',
        'bhim' => 'BHIM',
        'amazonpay' => 'Amazon Pay',
        'other_upi' => 'Another UPI app',
        'bank' => 'Bank transfer',
        'cash' => 'Cash',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'is_anonymous' => 'boolean',
            'confirmed_at' => 'datetime',
            'paid_on' => 'date',
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

    public function paymentAppLabel(): ?string
    {
        return self::PAYMENT_APPS[$this->payment_app] ?? null;
    }
}
