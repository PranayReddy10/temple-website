<?php

namespace App\Filament\Resources\DevotionalDays\Tables;

use App\Enums\TempleStatus;
use App\Models\DevotionalDay;
use App\Models\Temple;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DevotionalDaysTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query
                ->with('deity')
                ->withCount(['media as published_media_count' => fn ($q) => $q->where('is_published', true)]))
            ->columns([
                TextColumn::make('weekday')
                    ->label('Day')
                    ->formatStateUsing(fn (int $state): string => DevotionalDay::weekdayNames()[$state] ?? '—')
                    ->badge()
                    ->color(fn (DevotionalDay $record): string => $record->weekday === \App\Support\DevotionalClock::now()->dayOfWeek ? 'success' : 'gray')
                    ->description(fn (DevotionalDay $record): ?string => $record->weekday === \App\Support\DevotionalClock::now()->dayOfWeek ? 'Today' : null)
                    ->sortable(),

                TextColumn::make('deity.name')->label('Deity')->weight('medium')->sortable(),

                TextColumn::make('title')->label('Title')->wrap()->toggleable(),

                TextColumn::make('mantra_transliteration')
                    ->label('Mantra')
                    ->placeholder('—')
                    ->limit(30)
                    ->toggleable(),

                ColorColumn::make('accent_color')->label('Colour'),

                TextColumn::make('published_media_count')
                    ->label('Songs & media')
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'primary' : 'warning'),

                TextColumn::make('temple_count')
                    ->label('Temples')
                    ->state(fn (DevotionalDay $record): int => Temple::where('deity_id', $record->deity_id)
                        ->where('status', TempleStatus::Published)
                        ->count())
                    ->badge()
                    ->color('gray')
                    ->tooltip('Published temples of this deity, shown on the day'),

                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->filters([
                SelectFilter::make('weekday')->options(DevotionalDay::weekdayNames()),
                SelectFilter::make('deity')->relationship('deity', 'name')->searchable()->preload(),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])])
            ->defaultSort('weekday')
            ->emptyStateHeading('No devotional days yet')
            ->emptyStateDescription('Map each weekday to its deity, then add the songs devotees hear that day.');
    }
}
