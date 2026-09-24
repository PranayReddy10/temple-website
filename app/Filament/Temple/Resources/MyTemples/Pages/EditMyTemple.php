<?php

namespace App\Filament\Temple\Resources\MyTemples\Pages;

use App\Filament\Support\TempleQrActions;
use App\Filament\Temple\Resources\MyTemples\MyTempleResource;
use Filament\Resources\Pages\EditRecord;

class EditMyTemple extends EditRecord
{
    protected static string $resource = MyTempleResource::class;

    /**
     * The temple's check-in code, to print for its gate. No delete action: a
     * temple team cannot remove its own listing.
     */
    protected function getHeaderActions(): array
    {
        return TempleQrActions::make(fn () => $this->getRecord());
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
