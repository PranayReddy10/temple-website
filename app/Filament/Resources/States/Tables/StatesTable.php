<?php

namespace App\Filament\Resources\States\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class StatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount(['districts', 'temples']))
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),

                TextColumn::make('code')
                    ->badge()
                    ->sortable(),

                TextColumn::make('type')
                    ->formatStateUsing(fn (string $state): string => $state === 'state' ? 'State' : 'Union Territory')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('districts_count')
                    ->label('Districts')
                    ->sortable(),

                TextColumn::make('temples_count')
                    ->label('Temples')
                    ->badge()
                    ->color('primary')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options([
                        'state' => 'State',
                        'union_territory' => 'Union Territory',
                    ]),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ])
            ->defaultSort('name');
    }
}
