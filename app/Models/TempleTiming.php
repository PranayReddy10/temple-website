<?php

namespace App\Models;

use App\Enums\TimingKind;
use App\Support\Clock;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TempleTiming extends Model
{
    use HasFactory;

    protected $fillable = [
        'temple_id', 'kind', 'label', 'day_of_week', 'days',
        'opens_at', 'closes_at', 'notes', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'kind' => TimingKind::class,
            'day_of_week' => 'integer',
            'days' => 'array',
            'sort_order' => 'integer',
        ];
    }

    public function temple(): BelongsTo
    {
        return $this->belongsTo(Temple::class);
    }

    /** @return array<int, string> */
    public static function dayNames(): array
    {
        return [
            0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday',
            4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday',
        ];
    }

    /** Monday first, the way a week is read on a notice board. */
    public const WEEK = [1, 2, 3, 4, 5, 6, 0];

    public const WEEKDAYS = [1, 2, 3, 4, 5];

    public const WEEKEND = [6, 0];

    protected static function booted(): void
    {
        // `days` is the truth; `day_of_week` follows it for older apps. An
        // older trust app sends only `day_of_week`, which sets `days`.
        static::saving(function (TempleTiming $timing): void {
            if ($timing->isDirty('days') || ! $timing->exists) {
                $timing->days = self::normaliseDays($timing->days ?? ($timing->day_of_week !== null ? [$timing->day_of_week] : null));
            } elseif ($timing->isDirty('day_of_week')) {
                $timing->days = $timing->day_of_week === null ? null : [$timing->day_of_week];
            }
            $timing->day_of_week = $timing->days === null ? null : $timing->days[0];
        });
    }

    /**
     * Sorted Monday first, without repeats; every day (or none) is null.
     *
     * @return array<int, int>|null
     */
    public static function normaliseDays(mixed $days): ?array
    {
        if (! is_array($days)) {
            return null;
        }
        $days = array_values(array_intersect(self::WEEK, array_map('intval', array_filter($days, 'is_numeric'))));

        return $days === [] || count($days) === 7 ? null : $days;
    }

    /** @return array<int, int> the days this timing holds on */
    public function dayList(): array
    {
        return $this->days ?? self::WEEK;
    }

    public function isEveryDay(): bool
    {
        return $this->days === null;
    }

    public function appliesOn(int $dayOfWeek): bool
    {
        return in_array($dayOfWeek, $this->dayList(), true);
    }

    /**
     * The timings that hold on a day. A timing set for particular days
     * (a "Sat & Sun" darshan) takes the place of the every-day timing with
     * the same type and name that day, so a weekend shows its own hours
     * rather than both; other timings are untouched.
     *
     * @param  iterable<TempleTiming>  $timings
     * @return Collection<int, TempleTiming>
     */
    public static function forDay(iterable $timings, int $dayOfWeek): Collection
    {
        $on = collect($timings)->filter(fn (TempleTiming $t) => $t->appliesOn($dayOfWeek));
        $replaced = $on->reject(fn (TempleTiming $t) => $t->isEveryDay())->map(fn (TempleTiming $t) => $t->slotKey())->unique();

        return $on->reject(fn (TempleTiming $t) => $t->isEveryDay() && $replaced->contains($t->slotKey()))->values();
    }

    /** Which timing this is, whatever its days: its type and name. */
    public function slotKey(): string
    {
        return $this->kind?->value.'|'.mb_strtolower(trim((string) $this->label));
    }

    /**
     * "Different hours on Sat & Sun": each every-day timing becomes Mon–Fri,
     * and a copy is made for Sat & Sun with the same hours, ready to change.
     *
     * @param  iterable<TempleTiming>  $timings
     * @return Collection<int, TempleTiming> the weekend copies
     */
    public static function splitWeekend(iterable $timings): Collection
    {
        return DB::transaction(fn () => collect($timings)
            ->filter(fn (TempleTiming $t) => $t->isEveryDay())
            ->map(function (TempleTiming $t): TempleTiming {
                $copy = $t->replicate();
                $copy->days = self::WEEKEND;
                $copy->save();
                $t->update(['days' => self::WEEKDAYS]);

                return $copy;
            })->values());
    }

    /**
     * In the order a schedule is read: every day first, then by the first
     * day (Monday first), then as staff ordered them and by opening time.
     *
     * @param  iterable<TempleTiming>  $timings
     * @return Collection<int, TempleTiming>
     */
    public static function inReadingOrder(iterable $timings): Collection
    {
        return collect($timings)->sortBy([
            fn (TempleTiming $a, TempleTiming $b) => ($a->isEveryDay() ? -1 : array_search($a->dayList()[0], self::WEEK, true)) <=> ($b->isEveryDay() ? -1 : array_search($b->dayList()[0], self::WEEK, true)),
            fn (TempleTiming $a, TempleTiming $b) => count($b->dayList()) <=> count($a->dayList()),
            fn (TempleTiming $a, TempleTiming $b) => $a->sort_order <=> $b->sort_order,
            fn (TempleTiming $a, TempleTiming $b) => (string) $a->opens_at <=> (string) $b->opens_at,
        ])->values();
    }

    public function dayLabel(): string
    {
        return self::labelFor($this->days);
    }

    /** "Every day", "Mon–Fri", "Sat & Sun", "Sunday", "Mon, Wed & Fri". */
    public static function labelFor(?array $days): string
    {
        $days = self::normaliseDays($days);
        if ($days === null) {
            return 'Every day';
        }
        $short = [0 => 'Sun', 1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat'];
        if (count($days) === 1) {
            return self::dayNames()[$days[0]];
        }
        if ($days === self::WEEKEND) {
            return 'Sat & Sun';
        }
        // A run of three or more days in a row: "Mon–Fri", "Tue–Sun".
        $positions = array_map(fn ($d) => array_search($d, self::WEEK, true), $days);
        if (count($days) >= 3 && $positions === range($positions[0], $positions[0] + count($days) - 1)) {
            return $short[$days[0]].'–'.$short[end($days)];
        }
        $names = array_map(fn ($d) => $short[$d], $days);
        $last = array_pop($names);

        return implode(', ', $names).' & '.$last;
    }

    /**
     * Human-readable window, e.g. "4:00 AM – 9:30 PM". Temples routinely publish a
     * one-sided time ("opens 04:00"), so both halves are optional.
     */
    public function window(): string
    {
        $open = $this->formatTime($this->opens_at);
        $close = $this->formatTime($this->closes_at);

        return match (true) {
            $open !== null && $close !== null => "{$open} – {$close}",
            $open !== null => "From {$open}",
            $close !== null => "Until {$close}",
            default => 'Not published',
        };
    }

    /** "05:30:00" → "5:30 AM": devotees read the 12-hour clock. */
    protected function formatTime(?string $time): ?string
    {
        return Clock::twelve($time);
    }
}
