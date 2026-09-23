<?php

namespace App\Filament\Resources\TemplePujas\Pages;

use App\Filament\Resources\TemplePujas\TemplePujaResource;
use App\Models\TemplePuja;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTemplePuja extends EditRecord
{
    protected static string $resource = TemplePujaResource::class;

    public function getSubheading(): ?string
    {
        return $this->getRecord()->temple?->name;
    }

    protected function getHeaderActions(): array
    {
        return [
            // The way back to the whole temple, because a seva is almost
            // never the only thing that needed correcting.
            Action::make('open_temple')
                ->label('Open the temple')
                ->icon('heroicon-o-building-library')
                ->color('gray')
                ->url(fn (TemplePuja $record): ?string => $record->temple === null
                    ? null
                    : \App\Filament\Resources\Temples\TempleResource::getUrl('edit', ['record' => $record->temple]))
                ->visible(fn (TemplePuja $record): bool => $record->temple !== null),

            DeleteAction::make(),
        ];
    }
}
