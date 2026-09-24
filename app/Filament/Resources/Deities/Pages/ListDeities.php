<?php

namespace App\Filament\Resources\Deities\Pages;

use App\Filament\Resources\Deities\DeityResource;
use App\Models\Deity;
use App\Services\DeityImageFinder;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Throwable;

class ListDeities extends ListRecords
{
    protected static string $resource = DeityResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('fetchImages')
                ->label('Find images for all')
                ->icon('heroicon-o-photo')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Find public-domain images')
                ->modalDescription('Searches Wikimedia Commons for a public-domain painting of every deity that has no image yet, and saves it with its credit. Images you uploaded are never touched. You can replace any of them afterwards.')
                ->modalSubmitActionLabel('Find images')
                ->action(function (DeityImageFinder $finder): void {
                    $found = [];
                    $missing = [];

                    Deity::query()->where(fn ($q) => $q->whereNull('image_path')->orWhere('image_path', ''))->orderBy('sort_order')->get()->each(function (Deity $deity) use ($finder, &$found, &$missing): void {
                        try {
                            $candidate = $finder->find($deity);

                            if ($candidate === null) {
                                $missing[] = $deity->name;

                                return;
                            }

                            $finder->store($deity, $candidate);
                            $found[] = $deity->name;
                        } catch (Throwable) {
                            $missing[] = $deity->name;
                        }
                    });

                    Notification::make()
                        ->title(count($found) === 0 ? 'No new images found' : count($found).' deity images added')
                        ->body(trim((count($found) ? 'Added: '.implode(', ', $found).'. ' : '').(count($missing) ? 'Upload by hand: '.implode(', ', $missing).'.' : '')))
                        ->status(count($found) ? 'success' : 'warning')
                        ->persistent()
                        ->send();
                }),
            CreateAction::make(),
        ];
    }
}
