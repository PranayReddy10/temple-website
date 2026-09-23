<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Where a devotee's uploaded photo stands with the moderators.
 *
 * Pending is the default and cannot be otherwise: this is user-supplied
 * imagery attached by name to real places of worship, and the cost of showing
 * the wrong thing there is not measured in support tickets.
 */
enum PhotoModerationStatus: string implements HasColor, HasIcon, HasLabel
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Waiting for review',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
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

    /** Approval alone is not publication; the devotee must also have opted in. */
    public function allowsPublication(): bool
    {
        return $this === self::Approved;
    }
}
