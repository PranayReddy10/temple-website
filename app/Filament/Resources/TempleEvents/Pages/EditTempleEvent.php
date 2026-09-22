<?php

namespace App\Filament\Resources\TempleEvents\Pages;

use App\Filament\Resources\TempleEvents\TempleEventResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTempleEvent extends EditRecord
{
    protected static string $resource = TempleEventResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
