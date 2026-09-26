<?php

namespace App\Filament\Resources\SevaDrives\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Donations donors reported sending, and whether the organiser confirmed
 * receiving them. The money never passes through the platform.
 */
class DonationsRelationManager extends RelationManager
{
    protected static string $relationship = 'donations';

    protected static ?string $title = 'Donations';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-banknotes';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('devotee:id,name'))
            ->columns([
                TextColumn::make('devotee.name')->label('Donor')->placeholder('Deleted account')
                    ->description(fn ($record): ?string => $record->is_anonymous ? 'Asked to stay anonymous' : null),
                TextColumn::make('amount')->money('INR')->sortable(),
                TextColumn::make('upi_ref')->label('UPI ref')->placeholder('—')->copyable(),
                TextColumn::make('message')->placeholder('—')->limit(50),
                IconColumn::make('confirmed_at')->label('Organiser confirmed')->boolean()
                    ->state(fn ($record): bool => $record->confirmed_at !== null),
                TextColumn::make('created_at')->label('Reported')->since(),
            ])
            ->emptyStateHeading('No donations reported');
    }
}
