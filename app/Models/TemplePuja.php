<?php

namespace App\Models;

use App\Enums\PujaKind;
use App\Support\MediaUrl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TemplePuja extends Model
{
    use HasFactory;

    protected $fillable = [
        'temple_id', 'kind', 'name', 'description', 'image_disk', 'image_path',
        'includes', 'eligibility',
        'starts_at', 'duration_minutes', 'schedule_note',
        'fee_amount', 'fee_currency', 'is_free',
        'booking_url', 'booking_is_official', 'booking_note',
        'app_booking_enabled', 'fee_per_person', 'max_people_per_booking',
        'booking_advance_days', 'booking_capacity_per_day', 'booking_instructions',
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
        'kind' => 'puja',
        'fee_currency' => 'INR',
        'is_free' => false,
        'booking_is_official' => false,
        'app_booking_enabled' => false,
        'fee_per_person' => true,
        'max_people_per_booking' => 10,
        'booking_advance_days' => 30,
        'is_published' => true,
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'kind' => PujaKind::class,
            'fee_amount' => 'decimal:2',
            'is_free' => 'boolean',
            'booking_is_official' => 'boolean',
            'app_booking_enabled' => 'boolean',
            'fee_per_person' => 'boolean',
            'max_people_per_booking' => 'integer',
            'booking_advance_days' => 'integer',
            'booking_capacity_per_day' => 'integer',
            'is_published' => 'boolean',
            'duration_minutes' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function temple(): BelongsTo
    {
        return $this->belongsTo(Temple::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(PujaBooking::class);
    }

    /**
     * Resolves against the disk recorded on the row, not the currently
     * configured media disk, so images uploaded before a move to Spaces keep
     * working afterwards.
     */
    public function imageUrl(): ?string
    {
        if (blank($this->image_path)) {
            return null;
        }

        return MediaUrl::for($this->image_disk, $this->image_path);
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

    // --- Booking through the app ---

    /**
     * Whether a devotee can book this in the app right now.
     *
     * Switched on by the temple per seva, and only meaningful with a price
     * the app can charge or an explicit "free": a seva with no published
     * price cannot be paid for, so it cannot be booked either.
     */
    public function isBookableInApp(): bool
    {
        return $this->app_booking_enabled
            && $this->is_published
            && ($this->is_free || $this->fee_amount !== null);
    }

    /** What one booking for this many people costs, in paise. */
    public function amountPaiseFor(int $people): int
    {
        if ($this->is_free || $this->fee_amount === null) {
            return 0;
        }

        $each = (int) round(((float) $this->fee_amount) * 100);

        return $this->fee_per_person ? $each * max(1, $people) : $each;
    }

    public function requiresPayment(): bool
    {
        return ! $this->is_free && $this->fee_amount !== null && (float) $this->fee_amount > 0;
    }

    /** The last day a booking may be made for. */
    public function lastBookableDate(): \Carbon\CarbonImmutable
    {
        return \App\Support\DevotionalClock::now()->addDays(max(0, (int) $this->booking_advance_days))->startOfDay();
    }

    /** How many more bookings a day can take, or null when the temple set no limit. */
    public function remainingCapacityOn(\Carbon\CarbonInterface|string $date): ?int
    {
        if ($this->booking_capacity_per_day === null) {
            return null;
        }

        $taken = $this->bookings()
            ->whereDate('booked_for', $date)
            ->whereIn('status', [\App\Enums\BookingStatus::PendingPayment->value, \App\Enums\BookingStatus::Confirmed->value, \App\Enums\BookingStatus::Verified->value])
            ->count();

        return max(0, $this->booking_capacity_per_day - $taken);
    }
}
