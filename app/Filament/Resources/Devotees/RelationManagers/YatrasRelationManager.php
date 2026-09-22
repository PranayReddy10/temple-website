<?php

namespace App\Filament\Resources\Devotees\RelationManagers;

use App\Enums\YatraStatus;
use App\Models\Yatra;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** This devotee's planned pilgrimages. Read-only: the plan is theirs. */
class YatrasRelationManager extends RelationManager
{
    protected static string $relationship = 'yatras';

    protected static ?string $title = 'Trips';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-map';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('stops'))
            ->columns([
                TextColumn::make('title')->label('Trip')->searchable()->weight('medium')->wrap(),

                TextColumn::make('status')->badge()->sortable(),

                TextColumn::make('dates')
                    ->label('When')
                    ->state(fn (Yatra $record): string => $record->dateLabel()),

                TextColumn::make('stops_count')->label('Temples')->alignEnd(),

                TextColumn::make('progress')
                    ->label('Progress')
                    ->state(fn (Yatra $record): string => $record->progressLabel()),

                TextColumn::make('party_size')->label('Party')->placeholder('—')->alignEnd()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(YatraStatus::class)->multiple(),

                Filter::make('upcoming')
                    ->label('Still to happen')
                    ->query(fn (Builder $query): Builder => $query->upcoming())
                    ->toggle(),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateIcon('heroicon-o-map')
            ->emptyStateHeading('No trips planned');
    }
}
