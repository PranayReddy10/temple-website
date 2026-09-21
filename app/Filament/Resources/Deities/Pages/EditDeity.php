<?php

namespace App\Filament\Resources\Deities\Pages;

use App\Filament\Resources\Deities\DeityResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditDeity extends EditRecord
{
    protected static string $resource = DeityResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
