<?php

namespace App\Filament\Resources\Pages\Pages;

use App\Filament\Resources\Pages\PageResource;
use App\Models\Page;
use App\Support\DefaultPages;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditPage extends EditRecord
{
    protected static string $resource = PageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('open')->label('Open page')->icon('heroicon-o-arrow-top-right-on-square')->color('gray')
                ->url(fn (Page $record) => $record->url(), shouldOpenInNewTab: true),
            Action::make('restore')->label('Restore the default text')->icon('heroicon-o-arrow-uturn-left')->color('gray')
                ->visible(fn (Page $record) => DefaultPages::html($record->slug) !== null)
                ->requiresConfirmation()
                ->modalDescription('Replaces this page\'s text with the version it started with. Your changes to the text are lost.')
                ->action(function (Page $record): void {
                    $record->update(['body' => DefaultPages::html($record->slug)]);
                    $this->fillForm();
                    Notification::make()->title('Default text restored')->success()->send();
                }),
            DeleteAction::make()->hidden(fn (Page $record) => in_array($record->slug, Page::REQUIRED, true)),
        ];
    }
}
