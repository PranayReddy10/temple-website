<?php

namespace App\Filament\Resources\Festivals\Pages;

use App\Filament\Resources\Festivals\FestivalResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFestivals extends ListRecords
{
    protected static string $resource = FestivalResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }

    public function getSubheading(): ?string
    {
        return 'Computed from the panchang for New Delhi, as all-India almanacs are. A regional almanac can differ by a day: correct the date here and it stays corrected.';
    }
}
