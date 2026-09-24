<?php

namespace App\Filament\Temple\Resources\MyTemples\Tables;

use App\Models\Temple;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MyTemplesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['deity', 'state']))
            ->columns([
                TextColumn::make('name')->label('Temple')->weight('medium')->wrap(),

                TextColumn::make('deity.name')->label('Deity')->placeholder('—'),

                TextColumn::make('location')
                    ->label('Location')
                    ->state(fn (Temple $record): string => collect([$record->city, $record->state?->name])
                        ->filter()->join(', ') ?: '—'),

                TextColumn::make('status')->label('Status')->badge(),

                TextColumn::make('updated_at')->label('Last updated')->since(),
            ])
            ->recordActions([
                EditAction::make()->label('Manage'),
                Action::make('printQr')
                    ->label('Print QR')
                    ->icon('heroicon-o-printer')
                    ->color('gray')
                    ->url(fn (Temple $record): string => route('temples.qr.print', $record))
                    ->openUrlInNewTab(),
            ])
            ->emptyStateHeading('No temples yet')
            ->emptyStateDescription('Your claim has not been approved yet, or no temple has been assigned to your account. Contact the editorial team if you expected to see one here.')
            ->emptyStateIcon('heroicon-o-building-library');
    }
}
