<?php

namespace App\Filament\Resources\TempleCategories\Pages;

use App\Filament\Resources\TempleCategories\TempleCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTempleCategories extends ListRecords
{
    protected static string $resource = TempleCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
