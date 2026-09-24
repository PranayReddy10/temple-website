<?php

namespace App\Filament\Temple\Resources\MyTemples\Tables;

use App\Models\Temple;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;

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
                // The code to print for the gate, one tap from the list.
                Action::make('checkinQr')
                    ->label('QR code')
                    ->icon('heroicon-o-qr-code')
                    ->color('gray')
                    ->modalHeading('Check-in QR code')
                    ->modalContent(fn (Temple $record): View => view('filament.temples.qr', ['temple' => $record]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
            ])
            ->emptyStateHeading('No temples yet')
            ->emptyStateDescription('Your claim has not been approved yet, or no temple has been assigned to your account. Contact the editorial team if you expected to see one here.')
            ->emptyStateIcon('heroicon-o-building-library');
    }
}
