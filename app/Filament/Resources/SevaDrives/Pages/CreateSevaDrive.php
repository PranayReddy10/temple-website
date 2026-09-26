<?php

namespace App\Filament\Resources\SevaDrives\Pages;

use App\Filament\Resources\SevaDrives\Schemas\SevaDriveForm;
use App\Filament\Resources\SevaDrives\SevaDriveResource;
use App\Models\SevaDrive;
use App\Models\SevaDriveMedia;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class CreateSevaDrive extends CreateRecord
{
    protected static string $resource = SevaDriveResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $photos = array_values(array_filter((array) ($data['before_photos'] ?? [])));

        $drive = SevaDriveForm::fill(new SevaDrive(), $data);
        $drive->created_by = Auth::id();
        $drive->moderated_by = Auth::id();
        $drive->moderated_at = now();
        $drive->save();

        foreach ($photos as $path) {
            $drive->media()->create([
                'stage' => SevaDriveMedia::STAGE_BEFORE,
                'type' => SevaDriveMedia::TYPE_PHOTO,
                'disk' => config('filesystems.media'),
                'path' => $path,
            ]);
        }

        return $drive;
    }

    protected function getRedirectUrl(): string
    {
        return SevaDriveResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
