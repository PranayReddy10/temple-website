<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The deity associated with a day of the week.
 *
 * Monday is Shiva, Tuesday Hanuman, and so on. Kept as data rather than
 * constants because regional traditions differ and more than one deity per
 * day is normal.
 */
class DevotionalDay extends Model
{
    use HasFactory;

    protected $fillable = [
        'weekday', 'deity_id', 'title', 'subtitle', 'significance',
        'mantra', 'mantra_transliteration', 'accent_color',
        'sort_order', 'is_active',
    ];

    protected $attributes = [
        'is_active' => true,
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function deity(): BelongsTo
    {
        return $this->belongsTo(Deity::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(DevotionalMedia::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    // --- Scopes ---

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Entries for a given date's weekday.
     *
     * Takes a date rather than reading the clock so the caller controls the
     * timezone. "Today" for a devotee in India is decided by config('app.timezone'),
     * not by wherever the server happens to sit.
     */
    public function scopeForDate(Builder $query, ?CarbonInterface $date = null): Builder
    {
        $date = $date ?? now();

        return $query->where('weekday', $date->dayOfWeek);
    }

    // --- Helpers ---

    /** @return array<int, string> */
    public static function weekdayNames(): array
    {
        return [
            0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday',
            4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday',
        ];
    }

    public function weekdayName(): string
    {
        return self::weekdayNames()[$this->weekday] ?? 'Unknown';
    }

    /** Falls back to the brand saffron when a day has no colour of its own. */
    public function accentColor(): string
    {
        return $this->accent_color ?: config('brand.colors.saffron.hex');
    }

    /**
     * Published temples of this day's deity, for "Shiva temples on Monday".
     */
    public function temples(): Builder
    {
        return Temple::query()
            ->published()
            ->where('deity_id', $this->deity_id);
    }
}
