<?php

namespace App\Filament\Resources\Temples\Pages;

use App\Filament\Concerns\SyncsCoverPhoto;
use App\Filament\Resources\Temples\TempleResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditTemple extends EditRecord
{
    use SyncsCoverPhoto;

    protected static string $resource = TempleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    /** @param array<string, mixed> $data */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $this->fillCoverPhoto($data, $this->getRecord());
    }

    /** @param array<string, mixed> $data */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->extractCoverPhoto($data);
    }

    protected function afterSave(): void
    {
        $this->syncCoverPhoto($this->getRecord());
    }
}
