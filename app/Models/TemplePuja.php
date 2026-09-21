<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TemplePuja extends Model
{
    use HasFactory;

    protected $fillable = [
        'temple_id', 'name', 'description', 'includes', 'eligibility',
        'starts_at', 'duration_minutes', 'schedule_note',
        'fee_amount', 'fee_currency', 'is_free',
        'booking_url', 'booking_is_official', 'booking_note',
        'sort_order', 'is_published',
    ];

    /**
     * Defaults held on the model, not only in the database.
     *
     * A database default is not applied to the in-memory instance until it is
     * refreshed, so a freshly created puja had a null currency and its fee
     * rendered without the rupee symbol.
     */
    protected $attributes = [
        'fee_currency' => 'INR',
        'is_free' => false,
        'booking_is_official' => false,
        'is_published' => true,
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'fee_amount' => 'decimal:2',
            'is_free' => 'boolean',
            'booking_is_official' => 'boolean',
            'is_published' => 'boolean',
            'duration_minutes' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function temple(): BelongsTo
    {
        return $this->belongsTo(Temple::class);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    /**
     * How the fee should be shown.
     *
     * "No published price" is deliberately distinct from "Free". Presenting an
     * unknown price as free would mislead a devotee who then arrives to find a
     * charge, which is exactly what section 20 of the plan warns against.
     */
    public function feeLabel(): string
    {
        if ($this->is_free) {
            return 'Free';
        }

        if ($this->fee_amount === null) {
            return 'No published price';
        }

        $amount = number_format((float) $this->fee_amount, 2);
        // Treat a missing currency as INR rather than printing a bare number.
        $currency = $this->fee_currency ?: 'INR';

        return $currency === 'INR'
            ? "₹{$amount}"
            : "{$currency} {$amount}";
    }

    public function durationLabel(): ?string
    {
        if ($this->duration_minutes === null) {
            return null;
        }

        $hours = intdiv($this->duration_minutes, 60);
        $minutes = $this->duration_minutes % 60;

        return match (true) {
            $hours > 0 && $minutes > 0 => "{$hours} hr {$minutes} min",
            $hours > 0 => "{$hours} hr",
            default => "{$minutes} min",
        };
    }

    /**
     * Only an explicitly confirmed official route may be labelled official.
     * Anything else is shown as a third-party link, whatever the URL looks like.
     */
    public function hasOfficialBooking(): bool
    {
        return $this->booking_is_official && filled($this->booking_url);
    }

    public function bookingLabel(): ?string
    {
        if (blank($this->booking_url)) {
            return null;
        }

        return $this->booking_is_official
            ? 'Official booking'
            : 'Third-party link — not the official booking route';
    }
}
