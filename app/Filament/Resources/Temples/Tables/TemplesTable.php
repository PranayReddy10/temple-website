<?php

namespace App\Filament\Resources\Temples\Tables;

use App\Enums\TempleStatus;
use App\Enums\VerificationStatus;
use App\Models\Temple;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TemplesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Temple')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->description(fn (Temple $record): ?string => $record->short_description
                        ? str($record->short_description)->limit(70)->toString()
                        : null)
                    ->wrap(),

                TextColumn::make('deity.name')
                    ->label('Deity')
                    ->sortable()
                    ->toggleable()
                    ->placeholder('—'),

                TextColumn::make('district.name')
                    ->label('District')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),

                TextColumn::make('state.name')
                    ->label('State')
                    ->sortable()
                    ->toggleable()
                    ->placeholder('—'),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->sortable(),

                TextColumn::make('verification_status')
                    ->label('Trust')
                    ->badge()
                    ->sortable()
                    ->toggleable(),

                IconColumn::make('has_coordinates')
                    ->label('Map')
                    ->state(fn (Temple $record): bool => $record->hasCoordinates())
                    ->boolean()
                    ->trueIcon('heroicon-o-map-pin')
                    ->falseIcon('heroicon-o-exclamation-triangle')
                    ->trueColor('success')
                    ->falseColor('warning')
                    ->tooltip(fn (Temple $record): string => $record->hasCoordinates()
                        ? 'Coordinates recorded'
                        : 'No coordinates: will not appear on the map')
                    ->toggleable(),

                TextColumn::make('last_verified_at')
                    ->label('Verified')
                    ->date('d M Y')
                    ->sortable()
                    ->placeholder('Never')
                    ->color(fn (Temple $record): string => $record->isStale() ? 'danger' : 'success')
                    ->toggleable(),

                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(TempleStatus::class)
                    ->multiple(),

                SelectFilter::make('verification_status')
                    ->label('Trust level')
                    ->options(VerificationStatus::class)
                    ->multiple(),

                SelectFilter::make('state')
                    ->relationship('state', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('deity')
                    ->relationship('deity', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('categories')
                    ->label('Circuit / category')
                    ->relationship('categories', 'name')
                    ->multiple()
                    ->preload(),

                Filter::make('missing_coordinates')
                    ->label('Missing coordinates')
                    ->query(fn (Builder $query): Builder => $query->whereNull('latitude')->orWhereNull('longitude'))
                    ->toggle(),

                // Section 20 of the plan: stale timings must be flagged for review.
                Filter::make('stale')
                    ->label('Needs re-verification')
                    ->query(fn (Builder $query): Builder => $query->where(
                        fn (Builder $q) => $q->whereNull('last_verified_at')
                            ->orWhere('last_verified_at', '<', now()->subYear())
                    ))
                    ->toggle(),

                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ])
            ->defaultSort('updated_at', 'desc')
            ->persistFiltersInSession()
            ->persistSortInSession()
            ->emptyStateHeading('No temples yet')
            ->emptyStateDescription('The temple database is the core asset. Add the first record to get started.')
            ->emptyStateIcon('heroicon-o-building-library');
    }
}
