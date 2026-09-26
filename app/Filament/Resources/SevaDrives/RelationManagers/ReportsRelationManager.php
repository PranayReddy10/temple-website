<?php

namespace App\Filament\Resources\SevaDrives\RelationManagers;

use App\Filament\Resources\SupportTickets\SupportTicketResource;
use App\Models\SupportTicket;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * What people reported about this drive from the app — misleading, unsafe,
 * not what it claims. Each is a ticket in Support & Reports; answering one
 * happens there, so the conversation stays in one place.
 */
class ReportsRelationManager extends RelationManager
{
    protected static string $relationship = 'reports';

    protected static ?string $title = 'Reports';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-flag';

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $open = $ownerRecord->reports()->open()->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getBadgeColor(Model $ownerRecord, string $pageClass): ?string
    {
        return 'danger';
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('subject')
            ->columns([
                TextColumn::make('reference')->label('Ref')->fontFamily('mono')->size('xs'),
                TextColumn::make('category')->badge()->color('gray'),
                TextColumn::make('body')->label('What they said')->limit(80)->wrap(),
                TextColumn::make('reporter')->label('From')->state(fn (SupportTicket $record): string => $record->reporterName()),
                TextColumn::make('status')->badge(),
                TextColumn::make('created_at')->label('Filed')->since(),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('Answer')
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->url(fn (SupportTicket $record): string => SupportTicketResource::getUrl('view', ['record' => $record])),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Nobody has reported this drive');
    }
}
