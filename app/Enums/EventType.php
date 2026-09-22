<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum EventType: string implements HasColor, HasIcon, HasLabel
{
    case Festival = 'festival';
    case Program = 'program';
    case Puja = 'puja';
    case Announcement = 'announcement';

    public function getLabel(): string
    {
        return match ($this) {
            self::Festival => 'Festival',
            self::Program => 'Program',
            self::Puja => 'Special puja',
            self::Announcement => 'Announcement',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Festival => 'warning',
            self::Program => 'info',
            self::Puja => 'primary',
            self::Announcement => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Festival => 'heroicon-m-sparkles',
            self::Program => 'heroicon-m-calendar-days',
            self::Puja => 'heroicon-m-fire',
            self::Announcement => 'heroicon-m-megaphone',
        };
    }
}
