<?php

namespace App\Enums;

use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/** What a seva drive sets out to do for the place. */
enum SevaCause: string implements HasIcon, HasLabel
{
    case Cleaning = 'cleaning';
    case WaterBody = 'water_body';
    case Restoration = 'restoration';
    case Painting = 'painting';
    case Lighting = 'lighting';
    case Plantation = 'plantation';
    case Documentation = 'documentation';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Cleaning => 'Cleaning & clearing',
            self::WaterBody => 'Temple tank / stepwell',
            self::Restoration => 'Repair & restoration',
            self::Painting => 'Whitewash & painting',
            self::Lighting => 'Lamps & lighting',
            self::Plantation => 'Trees & garden',
            self::Documentation => 'Photographing & recording',
            self::Other => 'Something else',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Cleaning => 'heroicon-m-sparkles',
            self::WaterBody => 'heroicon-m-beaker',
            self::Restoration => 'heroicon-m-wrench-screwdriver',
            self::Painting => 'heroicon-m-paint-brush',
            self::Lighting => 'heroicon-m-light-bulb',
            self::Plantation => 'heroicon-m-sun',
            self::Documentation => 'heroicon-m-camera',
            self::Other => 'heroicon-m-hand-raised',
        };
    }

    /** A line for the app's picker. */
    public function description(): string
    {
        return match ($this) {
            self::Cleaning => 'Clearing litter, weeds and debris from an old temple or its grounds.',
            self::WaterBody => 'Desilting and cleaning a kalyani, pushkarini or stepwell.',
            self::Restoration => 'Fixing broken steps, walls or flooring, with permission.',
            self::Painting => 'Whitewashing walls and repainting where it is allowed.',
            self::Lighting => 'Lamps for a shrine that sits in the dark.',
            self::Plantation => 'Planting and tending trees or a nandavanam.',
            self::Documentation => 'Photographing inscriptions and carvings before they are lost.',
            self::Other => 'Any other care for a place that needs it.',
        };
    }
}
