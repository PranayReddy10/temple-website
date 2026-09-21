<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TempleClosure extends Model
{
    use HasFactory;

    protected $fillable = [
        'temple_id', 'starts_on', 'ends_on', 'reason',
        'is_full_day', 'opens_at', 'closes_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'is_full_day' => 'boolean',
        ];
    }

    public function temple(): BelongsTo
    {
        return $this->belongsTo(Temple::class);
    }

    /** A single-day closure leaves ends_on null. */
    public function lastDay(): CarbonInterface
    {
        return $this->ends_on ?? $this->starts_on;
    }

    /**
     * Closures are whole-day facts, but the value passed in is usually now(),
     * which carries a time. Comparing those directly made a closure dated today
     * report "open" from 00:00:01 onwards, because ends_on casts to midnight.
     * Both sides are normalised to day boundaries so the answer is the same at
     * any hour.
     */
    public function coversDate(CarbonInterface $date): bool
    {
        return $date->copy()->startOfDay()->betweenIncluded(
            $this->starts_on->copy()->startOfDay(),
            $this->lastDay()->copy()->startOfDay(),
        );
    }

    public function scopeActiveOn(Builder $query, CarbonInterface $date): Builder
    {
        return $query
            ->whereDate('starts_on', '<=', $date)
            ->where(function (Builder $q) use ($date) {
                $q->whereNull('ends_on')->whereDate('starts_on', '>=', $date)
                    ->orWhereDate('ends_on', '>=', $date);
            });
    }

    /** Closures that have not finished yet, for the "upcoming" panel. */
    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereDate('ends_on', '>=', now()->toDateString())
                ->orWhere(fn (Builder $inner) => $inner
                    ->whereNull('ends_on')
                    ->whereDate('starts_on', '>=', now()->toDateString()));
        });
    }
}
