<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Where a devotee's account of a visit stands with the moderators.
 *
 * Pending by default, like photos: this is somebody's words attached by
 * name to a place of worship, and it reaches other devotees only after a
 * person has read it.
 */
enum ReviewStatus: string implements HasColor, HasIcon, HasLabel
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Waiting for review',
            self::Approved => 'Published',
            self::Rejected => 'Not published',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Approved => 'success',
            self::Rejected => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Pending => 'heroicon-m-clock',
            self::Approved => 'heroicon-m-check-badge',
            self::Rejected => 'heroicon-m-x-circle',
        };
    }
}
