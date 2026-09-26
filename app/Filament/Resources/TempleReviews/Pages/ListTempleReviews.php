<?php

namespace App\Filament\Resources\TempleReviews\Pages;

use App\Filament\Resources\TempleReviews\TempleReviewResource;
use Filament\Resources\Pages\ListRecords;

class ListTempleReviews extends ListRecords
{
    protected static string $resource = TempleReviewResource::class;

    public function getHeading(): string
    {
        return 'Visit reviews';
    }

    public function getSubheading(): ?string
    {
        return 'Devotees rate the visit (queue, cleanliness, facilities, accessibility, how accurate our listing was), never the temple. Read each before it is published.';
    }
}
