<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * One attempt to pay, with one gateway.
 *
 * Its uuid is what the checkout page and the gateway's callback carry, so a
 * payment is never addressed by a guessable id from outside.
 */
class Payment extends Model
{
    public const CREATED = 'created';

    public const PENDING = 'pending';

    public const PAID = 'paid';

    public const FAILED = 'failed';

    public const REFUNDED = 'refunded';

    /** What a payment is for. */
    public const SUBSCRIPTION = 'subscription';

    public const PUJA_BOOKING = 'puja_booking';

    public const GATEWAYS = [
        'razorpay' => 'Razorpay',
        'phonepe' => 'PhonePe',
        'cashfree' => 'Cashfree',
        'payu' => 'PayU',
    ];

    protected $fillable = [
        'devotee_id', 'subscription_plan_id', 'purpose', 'gateway', 'amount_paise', 'currency',
        'status', 'gateway_order_id', 'gateway_payment_id', 'failure_reason', 'meta', 'paid_at',
    ];

    protected $attributes = [
        'purpose' => 'subscription',
        'currency' => 'INR',
        'status' => self::CREATED,
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'paid_at' => 'datetime',
            'amount_paise' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Payment $payment): void {
            $payment->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function devotee(): BelongsTo
    {
        return $this->belongsTo(Devotee::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(DevoteeSubscription::class);
    }

    /** The seva booking this payment confirms, when that is what it is for. */
    public function booking(): HasOne
    {
        return $this->hasOne(PujaBooking::class);
    }

    public function isForBooking(): bool
    {
        return $this->purpose === self::PUJA_BOOKING;
    }

    /**
     * What the devotee is paying for, in the words the gateway shows them:
     * the plan's name, or the seva and the temple.
     */
    public function description(): string
    {
        if ($this->isForBooking()) {
            $booking = $this->booking;

            return $booking === null
                ? 'Seva booking'
                : trim(($booking->puja?->name ?? 'Seva').' · '.($booking->temple?->name ?? ''), ' ·');
        }

        return $this->plan?->name ?? config('brand.name');
    }

    public function scopePaid(Builder $query): Builder
    {
        return $query->where('status', self::PAID);
    }

    public function isPaid(): bool
    {
        return $this->status === self::PAID;
    }

    public function isSettled(): bool
    {
        return in_array($this->status, [self::PAID, self::FAILED, self::REFUNDED], true);
    }

    public function amountLabel(): string
    {
        return '₹'.number_format($this->amount_paise / 100, 2);
    }
}
