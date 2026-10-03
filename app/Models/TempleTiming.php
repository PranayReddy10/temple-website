<?php

namespace App\Models;

use App\Enums\TimingKind;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TempleTiming extends Model
{
    use HasFactory;

    protected $fillable = [
        'temple_id', 'kind', 'label', 'day_of_week',
        'opens_at', 'closes_at', 'notes', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'kind' => TimingKind::class,
            'day_of_week' => 'integer',
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

    public function dayLabel(): string
    {
        return $this->day_of_week === null
            ? 'Every day'
            : (self::dayNames()[$this->day_of_week] ?? 'Unknown');
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
        if (blank($time) || ! preg_match('/^(\d{1,2}):(\d{2})/', $time, $m)) {
            return null;
        }
        $h = (int) $m[1];

        return sprintf('%d:%s %s', $h % 12 === 0 ? 12 : $h % 12, $m[2], $h < 12 ? 'AM' : 'PM');
    }
}
