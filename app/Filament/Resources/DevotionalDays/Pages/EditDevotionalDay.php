<?php

namespace App\Filament\Resources\DevotionalDays\Pages;

use App\Filament\Resources\DevotionalDays\DevotionalDayResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditDevotionalDay extends EditRecord
{
    protected static string $resource = DevotionalDayResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
