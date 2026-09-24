<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * How a visit came to be recorded, which is what decides whether it counts
 * as evidence or as a claim.
 *
 * The distinction is the whole basis of the Passport's credibility: a
 * collection where anyone can type in the twelve Jyotirlingas is a list, not
 * an achievement.
 */
enum CheckInMethod: string implements HasColor, HasIcon, HasLabel
{
    case Manual = 'manual';
    case Gps = 'gps';
    case Qr = 'qr';

    /** Marked at the temple by its own staff, after scanning the devotee's passport. */
    case Staff = 'staff';

    public function getLabel(): string
    {
        return match ($this) {
            self::Manual => 'Entered by hand',
            self::Gps => 'At the temple (GPS)',
            self::Qr => 'Scanned a temple code',
            self::Staff => 'Marked by temple staff',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Manual => 'gray',
            self::Gps => 'info',
            self::Qr => 'success',
            self::Staff => 'warning',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Manual => 'heroicon-m-pencil',
            self::Gps => 'heroicon-m-map-pin',
            self::Qr => 'heroicon-m-qr-code',
            self::Staff => 'heroicon-m-building-library',
        };
    }

    /**
     * Whether this method is evidence rather than an assertion.
     *
     * A manual entry is not dishonest — recording a pilgrimage from before
     * the app existed is exactly what a passport is for — but it cannot
     * verify itself, so it never auto-verifies.
     */
    public function isSelfVerifying(): bool
    {
        return $this !== self::Manual;
    }

    /**
     * Methods a devotee's own device may claim.
     *
     * A staff-marked visit is written only by the temple portal, so a
     * request from the app naming it is refused rather than trusted.
     *
     * @return array<int, self>
     */
    public static function deviceMethods(): array
    {
        return [self::Manual, self::Gps, self::Qr];
    }
}
