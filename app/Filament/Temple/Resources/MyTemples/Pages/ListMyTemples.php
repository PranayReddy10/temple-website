<?php

namespace App\Filament\Temple\Resources\MyTemples\Pages;

use App\Filament\Temple\Resources\MyTemples\MyTempleResource;
use Filament\Resources\Pages\ListRecords;

class ListMyTemples extends ListRecords
{
    protected static string $resource = MyTempleResource::class;

    public function getTitle(): string
    {
        return 'My Temples';
    }

    /** Creating temples is an editorial action, so there is no create button. */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
