<?php

namespace App\Filament\Resources\Deities\Tables;

use App\Filament\Support\MediaColumn;
use App\Models\Deity;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DeitiesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                MediaColumn::make(
                    'image',
                    fn (Deity $record): ?string => $record->image_path,
                    fn (Deity $record): string => $record->image_disk ?? config('filesystems.media'),
                )->label('')->height(44)->circular(),

                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->description(fn (Deity $record): ?string => $record->mantra_transliteration
                        ? str($record->mantra_transliteration)->squish()->limit(48)->toString()
                        : null),

                TextColumn::make('alternate_names')
                    ->label('Also known as')
                    ->limit(50)
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('temples_count')
                    ->label('Temples')
                    ->counts('temples')
                    ->sortable()
                    ->badge()
                    ->color('primary'),

                // What is still missing, at a glance: the two things a deity
                // page renders blank without.
                IconColumn::make('has_image')
                    ->label('Image')
                    ->state(fn (Deity $record): bool => filled($record->image_path))
                    ->boolean()
                    ->trueColor('success')
                    ->falseColor('warning')
                    ->tooltip(fn (Deity $record): string => filled($record->image_path)
                        ? 'Has an image'
                        : 'No image: the deity page and its day render without one')
                    ->toggleable(),

                IconColumn::make('has_mantra')
                    ->label('Mantra')
                    ->state(fn (Deity $record): bool => $record->hasMantra())
                    ->boolean()
                    ->trueColor('success')
                    ->falseColor('warning')
                    ->tooltip(fn (Deity $record): string => $record->hasMantra()
                        ? 'Has a mantra'
                        : 'No mantra: its days fall back to whatever they carry themselves')
                    ->toggleable(),

                TextColumn::make('media_count')
                    ->label('Songs')
                    ->counts('media')
                    ->sortable()
                    ->alignEnd()
                    ->toggleable(),

                TextColumn::make('sort_order')
                    ->label('Order')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),
            ])
            ->filters([
                \Filament\Tables\Filters\Filter::make('needs_image')
                    ->label('Missing an image')
                    ->query(fn ($query) => $query->whereNull('image_path'))
                    ->toggle(),

                \Filament\Tables\Filters\Filter::make('needs_mantra')
                    ->label('Missing a mantra')
                    ->query(fn ($query) => $query->whereNull('mantra'))
                    ->toggle(),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ])
            ->defaultSort('sort_order');
    }
}
