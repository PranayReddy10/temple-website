<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Something a devotee can buy: a price, a length and what it switches on.
 *
 * Benefits are data, not code, so a new plan ("Yatra year: no ads, ten
 * memory photos a visit") is made in the admin panel. The keys the app
 * understands are listed in BENEFITS.
 */
class SubscriptionPlan extends Model
{
    /** benefit key => [label, type]. */
    public const BENEFITS = [
        'no_ads' => ['No ads anywhere in the app', 'boolean'],
        'memory_photos_per_visit' => ['Memory photos per visit', 'integer'],
        'premium_passport' => ['Gold edition passport cover', 'boolean'],
    ];

    protected $fillable = [
        'code', 'name', 'description', 'price_paise', 'currency', 'duration_days',
        'benefits', 'badge', 'is_active', 'sort_order',
    ];

    protected $attributes = [
        'currency' => 'INR',
        'is_active' => true,
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'benefits' => 'array',
            'is_active' => 'boolean',
            'price_paise' => 'integer',
            'duration_days' => 'integer',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort_order')->orderBy('price_paise');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(DevoteeSubscription::class);
    }

    public function priceLabel(): string
    {
        $rupees = $this->price_paise / 100;

        return '₹'.(fmod($rupees, 1.0) === 0.0 ? number_format($rupees) : number_format($rupees, 2));
    }

    /** "Every month", "Every year", "30 days". */
    public function periodLabel(): string
    {
        return match ($this->duration_days) {
            30, 31 => 'per month',
            90 => 'per 3 months',
            180 => 'per 6 months',
            365, 366 => 'per year',
            default => 'for '.$this->duration_days.' days',
        };
    }
}
