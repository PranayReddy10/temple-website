<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * How far along a planned pilgrimage is.
 *
 * "How many devotees are planning a trip" is a question about this column,
 * and the answer is only useful if planning and completed are distinguishable
 * — a temple wants to know who is coming, not who came.
 */
enum YatraStatus: string implements HasColor, HasIcon, HasLabel
{
    case Planning = 'planning';
    case Confirmed = 'confirmed';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Abandoned = 'abandoned';

    public function getLabel(): string
    {
        return match ($this) {
            self::Planning => 'Planning',
            self::Confirmed => 'Confirmed',
            self::InProgress => 'On the road',
            self::Completed => 'Completed',
            self::Abandoned => 'Abandoned',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Planning => 'warning',
            self::Confirmed => 'info',
            self::InProgress => 'primary',
            self::Completed => 'success',
            self::Abandoned => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Planning => 'heroicon-m-pencil-square',
            self::Confirmed => 'heroicon-m-calendar-days',
            self::InProgress => 'heroicon-m-map',
            self::Completed => 'heroicon-m-flag',
            self::Abandoned => 'heroicon-m-archive-box',
        };
    }

    /**
     * A trip that has not happened yet but is intended to.
     *
     * The single definition of "upcoming demand", so the dashboard tile, the
     * API and any future notification all mean the same thing by it.
     */
    public function isUpcoming(): bool
    {
        return in_array($this, [self::Planning, self::Confirmed, self::InProgress], true);
    }

    /** @return array<int, string> */
    public static function upcomingValues(): array
    {
        return array_map(
            fn (self $case): string => $case->value,
            array_filter(self::cases(), fn (self $case): bool => $case->isUpcoming()),
        );
    }
}
