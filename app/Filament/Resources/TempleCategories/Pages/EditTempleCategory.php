<?php

namespace App\Filament\Resources\TempleCategories\Pages;

use App\Filament\Resources\TempleCategories\TempleCategoryResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTempleCategory extends EditRecord
{
    protected static string $resource = TempleCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
