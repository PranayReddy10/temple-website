<?php

namespace App\Filament\Temple\Resources\MyTemples\Pages;

use App\Filament\Temple\Resources\MyTemples\MyTempleResource;
use Filament\Resources\Pages\EditRecord;

class EditMyTemple extends EditRecord
{
    protected static string $resource = MyTempleResource::class;

    /** No delete action: a temple team cannot remove its own listing. */
    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
