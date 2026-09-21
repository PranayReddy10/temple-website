<?php

namespace App\Filament\Resources\TempleCategories\Tables;

use App\Models\TempleCategory;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TempleCategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // One aggregate query for the page instead of one per row.
            ->modifyQueryUsing(fn ($query) => $query->withCount('temples'))
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),

                TextColumn::make('kind')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === 'circuit' ? 'Circuit' : 'Type')
                    ->color(fn (string $state): string => $state === 'circuit' ? 'warning' : 'gray')
                    ->sortable(),

                TextColumn::make('completeness')
                    ->label('Recorded')
                    ->state(fn (TempleCategory $record): string => $record->expected_count
                        ? "{$record->temples_count} of {$record->expected_count}"
                        : (string) $record->temples_count)
                    ->badge()
                    ->color(function (TempleCategory $record): string {
                        if (! $record->expected_count) {
                            return 'gray';
                        }

                        return $record->temples_count >= $record->expected_count ? 'success' : 'warning';
                    }),

                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('kind')
                    ->options([
                        'circuit' => 'Pilgrimage circuit',
                        'type' => 'Temple type',
                    ]),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ])
            ->defaultSort('sort_order');
    }
}
