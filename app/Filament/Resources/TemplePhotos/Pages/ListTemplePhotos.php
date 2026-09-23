<?php

namespace App\Filament\Resources\TemplePhotos\Pages;

use App\Filament\Resources\TemplePhotos\TemplePhotoResource;
use Filament\Resources\Pages\ListRecords;

class ListTemplePhotos extends ListRecords
{
    protected static string $resource = TemplePhotoResource::class;

    public function getHeading(): string
    {
        return 'Temple Photos';
    }

    public function getSubheading(): ?string
    {
        return 'The photo library behind every listing. Add photos inside a temple; find the uncredited ones here.';
    }
}
