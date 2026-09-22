<?php

namespace App\Filament\Resources\VisitPhotos\Pages;

use App\Filament\Resources\VisitPhotos\VisitPhotoResource;
use Filament\Resources\Pages\ListRecords;

class ListVisitPhotos extends ListRecords
{
    protected static string $resource = VisitPhotoResource::class;

    public function getHeading(): string
    {
        return 'Photo Stamps';
    }

    public function getSubheading(): ?string
    {
        return 'Photos devotees took at temples. Approval makes a photo publishable; it becomes visible only if the devotee also chose to share it.';
    }
}
