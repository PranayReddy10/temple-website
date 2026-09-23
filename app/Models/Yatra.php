<?php

namespace App\Models;

use App\Enums\YatraStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A planned pilgrimage: temples, days, an order.
 */
class Yatra extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'devotee_id', 'title', 'description', 'status',
        'starts_on', 'ends_on', 'party_size', 'is_public',
    ];

    protected $attributes = [
        'status' => 'planning',
        'is_public' => false,
    ];

    protected function casts(): array
    {
        return [
            'status' => YatraStatus::class,
            'starts_on' => 'date',
            'ends_on' => 'date',
            'is_public' => 'boolean',
        ];
    }

    public function devotee(): BelongsTo
    {
        return $this->belongsTo(Devotee::class);
    }

    public function stops(): HasMany
    {
        return $this->hasMany(YatraStop::class)
            ->orderBy('day_number')
            ->orderBy('sort_order');
    }

    // --- Scopes ---

    /** Trips intended but not yet finished — "how many are planning a trip". */
    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->whereIn('status', YatraStatus::upcomingValues());
    }

    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_public', true);
    }

    public function scopeStartingWithin(Builder $query, int $days): Builder
    {
        return $query->whereNotNull('starts_on')
            ->whereDate('starts_on', '>=', now()->toDateString())
            ->whereDate('starts_on', '<=', now()->addDays($days)->toDateString());
    }

    // --- Helpers ---

    public function isUpcoming(): bool
    {
        return $this->status->isUpcoming();
    }

    /**
     * How many days the trip covers.
     *
     * Inclusive of both ends: a trip that starts and finishes on the same day
     * is one day, not zero.
     */
    public function dayCount(): ?int
    {
        if ($this->starts_on === null) {
            return null;
        }

        return $this->starts_on->diffInDays($this->ends_on ?? $this->starts_on) + 1;
    }

    public function dateLabel(): string
    {
        if ($this->starts_on === null) {
            return 'No dates yet';
        }

        $from = $this->starts_on->format('d M Y');
        $to = ($this->ends_on ?? $this->starts_on)->format('d M Y');

        return $from === $to ? $from : "{$from} – {$to}";
    }

    /** Stops whose visit has been recorded, over stops in total. */
    public function progressLabel(): string
    {
        $total = $this->stops()->count();

        if ($total === 0) {
            return 'No temples added';
        }

        $done = $this->stops()->whereNotNull('devotee_visit_id')->count();

        return "{$done} of {$total} visited";
    }
}
