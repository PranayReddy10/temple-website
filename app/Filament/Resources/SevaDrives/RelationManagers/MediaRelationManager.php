<?php

namespace App\Filament\Resources\SevaDrives\RelationManagers;

use App\Filament\Support\MediaColumn;
use App\Models\SevaDriveMedia;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Every photograph and video on a drive, with a way to take one down. */
class MediaRelationManager extends RelationManager
{
    protected static string $relationship = 'media';

    protected static ?string $title = 'Photos & videos';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-photo';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                MediaColumn::make('preview', fn (SevaDriveMedia $record): ?string => $record->type === SevaDriveMedia::TYPE_PHOTO ? $record->path : null)
                    ->label('')
                    ->height(56),
                TextColumn::make('stage')->badge()->color(fn (string $state): string => $state === SevaDriveMedia::STAGE_AFTER ? 'success' : 'gray'),
                TextColumn::make('type')->badge()->color('gray'),
                TextColumn::make('caption')->placeholder('—')->limit(40),
                IconColumn::make('is_hidden')->label('Hidden')->boolean(),
                TextColumn::make('created_at')->label('Added')->since(),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('Open')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('gray')
                    ->url(fn (SevaDriveMedia $record): ?string => $record->url())
                    ->openUrlInNewTab(),
                Action::make('toggle_hidden')
                    ->label(fn (SevaDriveMedia $record): string => $record->is_hidden ? 'Show' : 'Hide')
                    ->icon(fn (SevaDriveMedia $record): string => $record->is_hidden ? 'heroicon-o-eye' : 'heroicon-o-eye-slash')
                    ->color('warning')
                    ->action(function (SevaDriveMedia $record): void {
                        $record->is_hidden = ! $record->is_hidden;
                        $record->save();
                    }),
                DeleteAction::make(),
            ])
            ->defaultSort('stage')
            ->emptyStateHeading('Nothing uploaded');
    }
}
