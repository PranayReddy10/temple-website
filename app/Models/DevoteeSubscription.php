<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A plan a devotee holds, from a payment or granted by staff. */
class DevoteeSubscription extends Model
{
    protected $fillable = [
        'devotee_id', 'subscription_plan_id', 'payment_id', 'starts_at', 'ends_at',
        'cancelled_at', 'granted_by', 'note',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function devotee(): BelongsTo
    {
        return $this->belongsTo(Devotee::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('cancelled_at')
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>', now());
    }

    public function isCurrent(): bool
    {
        return $this->cancelled_at === null && $this->starts_at->isPast() && $this->ends_at->isFuture();
    }
}
