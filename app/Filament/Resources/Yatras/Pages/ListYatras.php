<?php

namespace App\Filament\Resources\Yatras\Pages;

use App\Filament\Resources\Yatras\YatraResource;
use Filament\Resources\Pages\ListRecords;

class ListYatras extends ListRecords
{
    protected static string $resource = YatraResource::class;

    public function getHeading(): string
    {
        return 'Trips & Yatras';
    }

    public function getSubheading(): ?string
    {
        return 'Pilgrimages devotees are planning. Read-only — a trip belongs to the devotee who planned it.';
    }
}
