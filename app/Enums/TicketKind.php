<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Whether somebody is asking for help or telling us something is wrong.
 *
 * One queue, two kinds. A report points at a record and usually means a
 * devotee has been misled by it; a support request is about the product or
 * the account.
 */
enum TicketKind: string implements HasColor, HasIcon, HasLabel
{
    case Support = 'support';
    case Report = 'report';

    public function getLabel(): string
    {
        return match ($this) {
            self::Support => 'Support request',
            self::Report => 'Report',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Support => 'info',
            self::Report => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Support => 'heroicon-m-lifebuoy',
            self::Report => 'heroicon-m-flag',
        };
    }

    /** A report is about a record; a support request usually is not. */
    public function isAboutARecord(): bool
    {
        return $this === self::Report;
    }
}
