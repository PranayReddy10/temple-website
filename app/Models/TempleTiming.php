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
     * Human-readable window, e.g. "04:00 – 21:30". Temples routinely publish a
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

    protected function formatTime(?string $time): ?string
    {
        if (blank($time)) {
            return null;
        }

        // Stored as H:i:s by MySQL but H:i by SQLite; normalise to H:i.
        return substr($time, 0, 5);
    }
}
