<?php

namespace App\Filament\Resources\SevaDrives\Pages;

use App\Filament\Resources\SevaDrives\Schemas\SevaDriveForm;
use App\Filament\Resources\SevaDrives\SevaDriveResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditSevaDrive extends EditRecord
{
    protected static string $resource = SevaDriveResource::class;

    protected function getHeaderActions(): array
    {
        return [ViewAction::make(), DeleteAction::make()];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        SevaDriveForm::fill($record, $data)->save();

        return $record;
    }

    protected function getRedirectUrl(): ?string
    {
        return SevaDriveResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
