<?php

namespace App\Models;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One time slot of a seva, like a show time: when it starts and ends, how
 * many people it takes a day, and which days of the week it runs.
 */
class TemplePujaSlot extends Model
{
    protected $fillable = ['temple_puja_id', 'starts_at', 'ends_at', 'capacity', 'days', 'is_active', 'sort_order'];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'days' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function puja(): BelongsTo
    {
        return $this->belongsTo(TemplePuja::class, 'temple_puja_id');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(PujaBooking::class, 'temple_puja_slot_id');
    }

    public function runsOn(CarbonInterface $date): bool
    {
        $days = array_map('intval', (array) ($this->days ?? []));

        return $days === [] || in_array($date->dayOfWeek, $days, true);
    }

    public function startTime(): string
    {
        return substr((string) $this->starts_at, 0, 5);
    }

    public function endTime(): ?string
    {
        return $this->ends_at ? substr((string) $this->ends_at, 0, 5) : null;
    }

    /** "9:00 – 10:00 AM", "6:30 PM – 7:15 PM", "From 6:00 AM". */
    public function label(): string
    {
        return self::window($this->startTime(), $this->endTime());
    }

    public static function window(?string $start, ?string $end): string
    {
        if ($start === null || $start === '') {
            return '';
        }
        $s = Carbon::createFromFormat('H:i', substr($start, 0, 5));
        if ($end === null || $end === '') {
            return 'From '.$s->format('g:i A');
        }
        $e = Carbon::createFromFormat('H:i', substr($end, 0, 5));

        return $s->format('A') === $e->format('A')
            ? $s->format('g:i').' – '.$e->format('g:i A')
            : $s->format('g:i A').' – '.$e->format('g:i A');
    }
}
