<?php

namespace App\Filament\Resources\Devotees\RelationManagers;

use App\Models\DevoteeMemory;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * What this devotee has written — and, for the private ones, only that they
 * wrote it.
 *
 * A pilgrimage journal is where someone records what they prayed for. Staff
 * need to know the feature is used and to be able to act on a report about a
 * shared one; they do not need to read a private one, and a screen that shows
 * it anyway is a screen that will be read out of curiosity. The preview is
 * withheld in the model rather than here, so no other list can leak it by
 * forgetting.
 */
class MemoriesRelationManager extends RelationManager
{
    protected static string $relationship = 'memories';

    protected static ?string $title = 'Memories';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-book-open';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('temple:id,name'))
            ->columns([
                TextColumn::make('preview')
                    ->label('Memory')
                    ->state(fn (DevoteeMemory $record): string => $record->preview())
                    ->color(fn (DevoteeMemory $record): ?string => $record->is_private ? 'gray' : null)
                    ->wrap(),

                TextColumn::make('temple.name')->label('Temple')->placeholder('—')->toggleable(),

                TextColumn::make('happened_on')
                    ->label('About')
                    ->date('d M Y')
                    ->placeholder('—')
                    ->sortable(),

                IconColumn::make('is_private')
                    ->label('Private')
                    ->boolean()
                    ->trueIcon('heroicon-o-lock-closed')
                    ->falseIcon('heroicon-o-lock-open')
                    ->trueColor('gray')
                    ->falseColor('warning')
                    ->tooltip(fn (DevoteeMemory $record): string => $record->is_private
                        ? 'Only the devotee can read this'
                        : 'The devotee chose to share this'),

                TextColumn::make('created_at')->label('Written')->since()->sortable(),
            ])
            ->filters([
                Filter::make('shared')
                    ->label('Shared ones only')
                    ->query(fn (Builder $query): Builder => $query->shared())
                    ->toggle(),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateIcon('heroicon-o-book-open')
            ->emptyStateHeading('Nothing written yet');
    }
}
