<?php

namespace App\Filament\Resources\SevaDrives\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Who said they are coming. */
class VolunteersRelationManager extends RelationManager
{
    protected static string $relationship = 'volunteers';

    protected static ?string $title = 'Volunteers';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-user-group';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('devotee:id,name,email'))
            ->columns([
                TextColumn::make('devotee.name')->label('Devotee')->description(fn ($record): ?string => $record->devotee?->email),
                TextColumn::make('party_size')->label('Coming')->numeric(),
                TextColumn::make('note')->placeholder('—')->limit(50),
                IconColumn::make('attended')->boolean()->placeholder('Not marked'),
                TextColumn::make('created_at')->label('Joined')->since(),
            ])
            ->emptyStateHeading('Nobody has joined yet');
    }
}
