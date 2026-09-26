<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Where a seva drive is in its life. Whether staff have verified it is a
 * separate badge (verified_at), not a stage: verifying a drive must not end it.
 *
 * A new drive goes straight to Approved (listed, marked "not verified"),
 * unless the admin has switched on "Seva drives need approval", when it
 * waits as Pending. Completed is reached when its last day is over or the
 * organiser says it is done.
 */
enum SevaDriveStatus: string implements HasColor, HasIcon, HasLabel
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /** Taken down by staff. Seen only by staff and, with the reason, the organiser. */
    case Blocked = 'blocked';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Waiting for review',
            self::Approved => 'Open for volunteers',
            self::Rejected => 'Not approved',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
            self::Blocked => 'Blocked',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Approved => 'info',
            self::Rejected => 'danger',
            self::Completed => 'primary',
            self::Cancelled => 'gray',
            self::Blocked => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Pending => 'heroicon-m-clock',
            self::Approved => 'heroicon-m-user-group',
            self::Rejected => 'heroicon-m-x-circle',
            self::Completed => 'heroicon-m-flag',
            self::Cancelled => 'heroicon-m-no-symbol',
            self::Blocked => 'heroicon-m-shield-exclamation',
        };
    }

    /** Whether anyone but the organiser and staff may see it. */
    public function isPublic(): bool
    {
        return in_array($this, [self::Approved, self::Completed], true);
    }

    public function acceptsVolunteers(): bool
    {
        return $this === self::Approved;
    }

    /** Before-photos and the plan may still change. */
    public function isEditableByOrganiser(): bool
    {
        return in_array($this, [self::Pending, self::Rejected, self::Approved], true);
    }
}
