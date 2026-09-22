<?php

namespace App\Filament\Resources\TempleAccess\Pages;

use App\Filament\Resources\TempleAccess\TempleAccessResource;
use Filament\Resources\Pages\ListRecords;

class ListTempleAccess extends ListRecords
{
    protected static string $resource = TempleAccessResource::class;

    /** Match the side-menu label, so the page you land on is the one you clicked. */
    public function getHeading(): string
    {
        return 'Temple Trust Access';
    }

    public function getSubheading(): ?string
    {
        return 'Who may sign in at /temple and maintain a temple\'s own listing. Grant it here or from inside the temple record.';
    }
}
