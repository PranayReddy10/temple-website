<?php

namespace App\Enums;

use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * What a temple lists under its sevas.
 *
 * A puja is performed for the devotee; a seva is offered by them (a lamp, a
 * garland, a day's annadanam); prasadam is taken home. Three words a temple
 * uses without thinking, and the app groups its list by them. Booking works
 * the same way for all three: prasadam ordered in the app is collected at
 * the counter, and posting it home is the phase after this one.
 */
enum PujaKind: string implements HasIcon, HasLabel
{
    case Puja = 'puja';
    case Seva = 'seva';
    case Prasadam = 'prasadam';

    public function getLabel(): string
    {
        return match ($this) {
            self::Puja => 'Puja',
            self::Seva => 'Seva',
            self::Prasadam => 'Prasadam',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Puja => 'heroicon-o-fire',
            self::Seva => 'heroicon-o-sparkles',
            self::Prasadam => 'heroicon-o-gift',
        };
    }

    /** The heading the app shows over each group. */
    public function plural(): string
    {
        return match ($this) {
            self::Puja => 'Pujas',
            self::Seva => 'Sevas',
            self::Prasadam => 'Prasadam',
        };
    }
}
