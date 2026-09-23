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
use Filament\Tables\Grouping\Group;
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

                IconColumn::make('is_featured')
                    ->label('Famous')
                    ->boolean()
                    ->trueIcon('heroicon-s-star')
                    ->falseIcon('heroicon-o-star')
                    ->trueColor('warning')
                    ->falseColor('gray')
                    ->sortable()
                    ->toggleable(),

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

                Filter::make('featured')
                    ->label('Famous temples')
                    ->query(fn (Builder $query): Builder => $query->where('is_featured', true))
                    ->toggle(),

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

                // The group matters: an ungrouped orWhere escapes every other
                // active filter, so combining this with a status filter would
                // quietly return unpublished temples that do have coordinates.
                Filter::make('missing_coordinates')
                    ->label('Missing coordinates')
                    ->query(fn (Builder $query): Builder => $query->where(
                        fn (Builder $q) => $q->whereNull('latitude')->orWhereNull('longitude')
                    ))
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
            /*
             * State-wise and god-wise, which is how anyone actually thinks
             * about this list.
             *
             * "Which Shiva temples do we have" and "what is missing in
             * Telangana" are the two questions that come up constantly, and
             * a flat list of two thousand rows answers neither. Grouping
             * beats separate screens here because the filters, the search
             * and the columns all keep working inside a group.
             */
            ->groups([
                Group::make('state.name')
                    ->label('State')
                    ->collapsible(),

                Group::make('deity.name')
                    ->label('Deity')
                    ->collapsible(),

                Group::make('district.name')
                    ->label('District')
                    ->collapsible(),

                Group::make('status')
                    ->label('Status')
                    ->collapsible(),

                Group::make('verification_status')
                    ->label('Trust level')
                    ->collapsible(),
            ])
            ->groupingSettingsInDropdownOnDesktop()
            ->defaultSort('updated_at', 'desc')
            ->persistFiltersInSession()
            ->persistSearchInSession()
            ->persistSortInSession()
            ->emptyStateHeading('No temples yet')
            ->emptyStateDescription('The temple database is the core asset. Add the first record to get started.')
            ->emptyStateIcon('heroicon-o-building-library');
    }
}
