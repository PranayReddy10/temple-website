<?php

namespace App\Filament\Resources\Deities\Pages;

use App\Filament\Resources\Deities\DeityResource;
use App\Services\DeityImageFinder;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Throwable;

class EditDeity extends EditRecord
{
    protected static string $resource = DeityResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('fetchImage')
                ->label('Find a public-domain image')
                ->icon('heroicon-o-photo')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription('Searches Wikimedia Commons for a public-domain painting of this deity and makes it the image, with its credit. This replaces the current image.')
                ->action(function (DeityImageFinder $finder): void {
                    try {
                        $candidate = $finder->find($this->getRecord());
                    } catch (Throwable $e) {
                        Notification::make()->title('Wikimedia Commons could not be reached')->body($e->getMessage())->danger()->send();

                        return;
                    }

                    if ($candidate === null) {
                        Notification::make()->title('No public-domain painting found')->body('Upload one below, with its credit.')->warning()->send();

                        return;
                    }

                    $finder->store($this->getRecord(), $candidate);
                    $this->fillForm();

                    Notification::make()->title('Image added')->body($candidate['title'])->success()->send();
                }),
            DeleteAction::make(),
        ];
    }
}
