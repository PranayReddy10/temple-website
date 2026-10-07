<?php

namespace App\Filament\Resources\Temples\Tables;

use App\Enums\TempleStatus;
use App\Enums\VerificationStatus;
use App\Filament\Support\MediaColumn;
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
            // The cover's row, once for the page rather than once per temple.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('primaryPhoto')
                // For the Page column: what the public page still lacks.
                ->withCount([
                    'photos as published_photos_count' => fn (Builder $q) => $q->published(),
                    'timings',
                    'pujas',
                    'translations as reviewed_names_count' => fn (Builder $q) => $q->where('field', 'name')->where('is_reviewed', true),
                ]))
            ->columns([
                // What devotees see first, at a glance, before opening the
                // temple: its thumbnail, or "No cover" (Filter: Without a cover).
                MediaColumn::make(
                    'cover',
                    fn (Temple $record): ?string => $record->primaryPhoto?->thumbnail_path ?? $record->primaryPhoto?->path,
                    fn (Temple $record): string => $record->primaryPhoto?->disk ?? config('filesystems.media'),
                )
                    ->label('Cover')
                    ->imageSize(44)
                    ->square()
                    ->extraImgAttributes(['loading' => 'lazy', 'class' => 'rounded-md'])
                    ->placeholder('No cover'),

                TextColumn::make('name')
                    ->label('Temple')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->description(fn (Temple $record): ?string => $record->short_description
                        ? str($record->short_description)->limit(70)->toString()
                        : null)
                    ->wrap(),

                // How complete the public page is: what makes it show up when
                // someone searches the temple's name (Temple::pageChecklist).
                TextColumn::make('page_score')
                    ->label('Page score')
                    ->state(fn (Temple $record): int => $record->pageChecklist()['score'])
                    ->formatStateUsing(fn (int $state): string => $state.'%')
                    ->badge()
                    ->color(fn (int $state): string => match (true) {
                        $state >= 80 => 'success',
                        $state >= 50 => 'warning',
                        default => 'danger',
                    })
                    // Written out under the score, not only on hover: a
                    // phone has no hover, and this is the to-do list.
                    ->description(fn (Temple $record): string => ($missing = $record->pageChecklist()['missing']) === []
                        ? 'Complete'
                        : 'Add: '.implode(', ', array_slice($missing, 0, 3)).(count($missing) > 3 ? ' +'.(count($missing) - 3).' more' : ''))
                    ->tooltip(fn (Temple $record): ?string => ($missing = $record->pageChecklist()['missing']) === []
                        ? 'Complete'
                        : 'Missing: '.implode(', ', $missing))
                    ->wrap()
                    ->toggleable(),

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

                Filter::make('without_timings')
                    ->label('Without timings')
                    ->query(fn (Builder $query): Builder => $query->whereDoesntHave('timings'))
                    ->toggle(),

                Filter::make('few_photos')
                    ->label('Fewer than 3 photos')
                    ->query(fn (Builder $query): Builder => $query->whereHas('photos', fn (Builder $q) => $q->published(), '<', 3))
                    ->toggle(),

                Filter::make('without_description')
                    ->label('Without a description')
                    ->query(fn (Builder $query): Builder => $query->where(fn (Builder $q) => $q->whereNull('short_description')->orWhere('short_description', '')))
                    ->toggle(),

                Filter::make('without_cover')
                    ->label('Without a cover')
                    ->query(fn (Builder $query): Builder => $query->whereDoesntHave('primaryPhoto'))
                    ->toggle(),

                // Section 20 of the plan: stale timings must be flagged for review.
                Filter::make('stale')
                    ->label('Needs re-verification')
                    ->query(fn (Builder $query): Builder => $query->where(
                        fn (Builder $q) => $q->whereNull('last_verified_at')
                            ->orWhere('last_verified_at', '<', now()->subYear())
                    ))
                    ->toggle(),

                // Read from the temple's own website, not yet looked at by staff
                // (temples:read-official-sites, or "Read official website").
                Filter::make('official_to_review')
                    ->label('Imported details to review')
                    ->query(fn (Builder $query): Builder => $query->whereNotNull('official_import')->whereNull('official_import_reviewed_at'))
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
