<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum TimingKind: string implements HasColor, HasLabel
{
    case General = 'general';
    case Darshan = 'darshan';
    case Aarti = 'aarti';
    case Special = 'special';

    public function getLabel(): string
    {
        return match ($this) {
            self::General => 'General opening hours',
            self::Darshan => 'Darshan',
            self::Aarti => 'Aarti / ritual',
            self::Special => 'Special day',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::General => 'gray',
            self::Darshan => 'primary',
            self::Aarti => 'warning',
            self::Special => 'info',
        };
    }
}
