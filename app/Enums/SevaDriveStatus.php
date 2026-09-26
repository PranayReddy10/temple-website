<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Where a seva drive stands.
 *
 * Pending is the default and cannot be otherwise: a drive is an invitation
 * to strangers to meet at a place on a date, and it reaches nobody until
 * staff have looked at it. Money is asked for only once the result has been
 * verified — not on a promise.
 */
enum SevaDriveStatus: string implements HasColor, HasIcon, HasLabel
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Completed = 'completed';
    case Verified = 'verified';
    case Cancelled = 'cancelled';

    /** Taken down by staff. Seen only by staff and, with the reason, the organiser. */
    case Blocked = 'blocked';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Waiting for review',
            self::Approved => 'Open for volunteers',
            self::Rejected => 'Not approved',
            self::Completed => 'Done — verifying',
            self::Verified => 'Verified',
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
            self::Verified => 'success',
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
            self::Completed => 'heroicon-m-camera',
            self::Verified => 'heroicon-m-check-badge',
            self::Cancelled => 'heroicon-m-no-symbol',
            self::Blocked => 'heroicon-m-shield-exclamation',
        };
    }

    /** Whether anyone but the organiser and staff may see it. */
    public function isPublic(): bool
    {
        return in_array($this, [self::Approved, self::Completed, self::Verified], true);
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

    /** The only state in which a UPI ID is ever served. */
    public function allowsDonations(): bool
    {
        return $this === self::Verified;
    }
}
