<?php

namespace App\Filament\Resources\Devotees\RelationManagers;

use App\Enums\CheckInMethod;
use App\Models\DevoteeVisit;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * This devotee's Passport.
 *
 * Read-only apart from verification. Staff cannot invent a visit — the whole
 * value of the collection is that its rows came from the devotee being
 * somewhere — but they can confirm one that the device could not, which is
 * how a pilgrimage from before the app existed becomes a stamp, and revoke
 * one that turns out to be false.
 */
class VisitsRelationManager extends RelationManager
{
    protected static string $relationship = 'visits';

    protected static ?string $title = 'Passport';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-map-pin';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('temple:id,name,city'))
            ->columns([
                TextColumn::make('temple.name')
                    ->label('Temple')
                    ->searchable()
                    ->weight('medium')
                    ->description(fn (DevoteeVisit $record): ?string => $record->temple?->city)
                    ->wrap(),

                TextColumn::make('visited_on')->label('Visited')->date('d M Y')->sortable(),

                TextColumn::make('method')->label('How')->badge(),

                IconColumn::make('is_verified')
                    ->label('Stamp')
                    ->boolean()
                    ->tooltip(fn (DevoteeVisit $record): string => $record->is_verified
                        ? 'Counts towards their collection'
                        : 'Recorded, but not a stamp'),

                TextColumn::make('distance_metres')
                    ->label('Distance')
                    ->formatStateUsing(fn (?int $state): string => $state === null
                        ? '—'
                        : ($state < 1000 ? $state.' m' : round($state / 1000, 1).' km'))
                    ->tooltip('How far the check-in was from the temple')
                    ->toggleable(),

                IconColumn::make('is_public')->label('Shared')->boolean()->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')->label('Recorded')->since()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('method')->label('How')->options(CheckInMethod::class),

                Filter::make('verified')
                    ->label('Stamps only')
                    ->query(fn (Builder $query): Builder => $query->verified())
                    ->toggle(),
            ])
            ->recordActions([
                Action::make('verify')
                    ->label('Verify')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn (DevoteeVisit $record): bool => ! $record->is_verified
                        && (Auth::user()?->canPublish() ?? false))
                    ->requiresConfirmation()
                    ->modalDescription('This makes the visit count towards their collection and any circuit it belongs to.')
                    ->action(fn (DevoteeVisit $record) => $record->update([
                        'is_verified' => true,
                        'verified_at' => now(),
                    ])),

                Action::make('unverify')
                    ->label('Revoke stamp')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->visible(fn (DevoteeVisit $record): bool => (bool) $record->is_verified
                        && (Auth::user()?->canPublish() ?? false))
                    ->requiresConfirmation()
                    ->modalDescription('The visit is kept; it stops counting as a stamp.')
                    ->action(fn (DevoteeVisit $record) => $record->update([
                        'is_verified' => false,
                        'verified_at' => null,
                    ])),
            ])
            ->defaultSort('visited_on', 'desc')
            ->emptyStateIcon('heroicon-o-map-pin')
            ->emptyStateHeading('No visits recorded')
            ->emptyStateDescription('Visits appear here as this devotee checks in at temples.');
    }
}
