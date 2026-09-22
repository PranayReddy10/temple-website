<?php

namespace App\Filament\Resources\DevotionalDays\Pages;

use App\Filament\Resources\DevotionalDays\DevotionalDayResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDevotionalDays extends ListRecords
{
    protected static string $resource = DevotionalDayResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add a day')];
    }
}
