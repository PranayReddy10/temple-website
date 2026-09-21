<?php

namespace App\Filament\Resources\Deities\Pages;

use App\Filament\Resources\Deities\DeityResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDeities extends ListRecords
{
    protected static string $resource = DeityResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
