<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Where a seva booking stands.
 *
 * Confirmed is the one that matters at the counter: it means the temple
 * has been paid (or the seva is free) and the devotee should be received.
 * Verified means the counter scanned the code, and a code is verified once:
 * a second scan is refused, so a screenshot cannot be used twice.
 */
enum BookingStatus: string implements HasColor, HasIcon, HasLabel
{
    case PendingPayment = 'pending_payment';
    case Confirmed = 'confirmed';
    case Verified = 'verified';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';

    public function getLabel(): string
    {
        return match ($this) {
            self::PendingPayment => 'Awaiting payment',
            self::Confirmed => 'Confirmed',
            self::Verified => 'Verified at the temple',
            self::Cancelled => 'Cancelled',
            self::Refunded => 'Refunded',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::PendingPayment => 'warning',
            self::Confirmed => 'info',
            self::Verified => 'success',
            self::Cancelled => 'gray',
            self::Refunded => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::PendingPayment => 'heroicon-o-clock',
            self::Confirmed => 'heroicon-o-ticket',
            self::Verified => 'heroicon-o-check-badge',
            self::Cancelled => 'heroicon-o-x-circle',
            self::Refunded => 'heroicon-o-receipt-refund',
        };
    }

    /** A booking the temple should expect somebody for. */
    public function isLive(): bool
    {
        return in_array($this, [self::Confirmed, self::Verified], true);
    }
}
