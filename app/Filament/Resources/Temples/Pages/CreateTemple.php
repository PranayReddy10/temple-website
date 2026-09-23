<?php

namespace App\Filament\Resources\Temples\Pages;

use App\Filament\Concerns\SyncsCoverPhoto;
use App\Filament\Resources\Temples\TempleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTemple extends CreateRecord
{
    use SyncsCoverPhoto;

    protected static string $resource = TempleResource::class;

    /** @param array<string, mixed> $data */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->extractCoverPhoto($data);
    }

    /**
     * The whole point of the field: a temple can be given its lead image
     * while it is being created, rather than only afterwards through a
     * relation manager that does not exist yet.
     */
    protected function afterCreate(): void
    {
        $this->syncCoverPhoto($this->getRecord());
    }
}
