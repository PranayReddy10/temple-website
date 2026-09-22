<?php

namespace App\Filament\Resources\TempleEvents\Pages;

use App\Filament\Resources\TempleEvents\TempleEventResource;
use Filament\Resources\Pages\ListRecords;

class ListTempleEvents extends ListRecords
{
    protected static string $resource = TempleEventResource::class;

    /** Match the side-menu label, so the page you land on is the one you clicked. */
    public function getHeading(): string
    {
        return 'Events & Programs';
    }

    public function getSubheading(): ?string
    {
        return 'Festivals, programs and announcements across every temple. Add one from inside a temple record; review submissions here.';
    }
}
