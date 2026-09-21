<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Project plan, section 20: official, verified, community and sponsored content
 * must be clearly labelled and never blurred together. This enum is the single
 * source of truth for that distinction across admin, API and app.
 */
enum VerificationStatus: string implements HasColor, HasLabel
{
    case Unverified = 'unverified';
    case Community = 'community';
    case Verified = 'verified';
    case Official = 'official';

    public function getLabel(): string
    {
        return match ($this) {
            self::Unverified => 'Unverified',
            self::Community => 'Community contributed',
            self::Verified => 'Verified by editor',
            self::Official => 'Official temple source',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Unverified => 'gray',
            self::Community => 'info',
            self::Verified => 'warning',
            self::Official => 'success',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Unverified => 'Not yet checked against any source.',
            self::Community => 'Submitted by a user and not independently confirmed.',
            self::Verified => 'Cross-checked by an editor against a credible source.',
            self::Official => 'Confirmed by the temple authority or a government source.',
        };
    }

    /** Whether a source reference is required before this level can be claimed. */
    public function requiresSource(): bool
    {
        return in_array($this, [self::Verified, self::Official], true);
    }
}
