<?php

namespace App\Filament\Resources\SupportTickets;

use App\Enums\TicketCategory;
use App\Enums\TicketKind;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Filament\Resources\SupportTickets\Pages\ListSupportTickets;
use App\Filament\Resources\SupportTickets\Pages\ViewSupportTicket;
use App\Filament\Resources\SupportTickets\RelationManagers\MessagesRelationManager;
use App\Models\SupportTicket;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Everything anybody has told us, and what we did about it.
 *
 * One queue for support requests and content reports. They are the same shape
 * — somebody says something is wrong and waits for an answer — and two queues
 * would mean two places to look and one of them going unread.
 *
 * Reports are the half that matters most. A listing with the wrong timings
 * sends devotees to a closed gate and nobody on the team will notice on their
 * own; the only way we find out is that somebody tells us, so the path for
 * telling us has to be answered rather than merely to exist.
 */
class SupportTicketResource extends Resource
{
    protected static ?string $model = SupportTicket::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-lifebuoy';

    protected static string|\UnitEnum|null $navigationGroup = 'Support';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Support & Reports';

    protected static ?string $modelLabel = 'ticket';

    protected static ?string $slug = 'support';

    protected static ?string $recordTitleAttribute = 'subject';

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with([
                'devotee:id,name,email', 'user:id,name,email', 'assignee:id,name',
            ]))
            ->columns([
                TextColumn::make('reference')
                    ->label('Ref')
                    ->searchable()
                    ->copyable()
                    ->fontFamily('mono')
                    ->size('xs')
                    ->toggleable(),

                TextColumn::make('kind')->label('Type')->badge()->sortable(),

                TextColumn::make('subject')
                    ->searchable()
                    ->weight('medium')
                    ->wrap()
                    ->description(fn (SupportTicket $record): ?string => $record->aboutLabel()),

                TextColumn::make('category')->badge()->color('gray')->sortable()->toggleable(),

                TextColumn::make('reporter')
                    ->label('From')
                    ->state(fn (SupportTicket $record): string => $record->reporterName())
                    ->description(fn (SupportTicket $record): ?string => $record->reporterEmail())
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->where('reporter_name', 'like', "%{$search}%")
                        ->orWhere('reporter_email', 'like', "%{$search}%")
                        ->orWhereHas('devotee', fn ($q) => $q->where('name', 'like', "%{$search}%"))),

                TextColumn::make('priority')->badge()->sortable()->toggleable(),

                TextColumn::make('status')->badge()->sortable(),

                TextColumn::make('assignee.name')
                    ->label('With')
                    ->placeholder('Nobody')
                    ->color(fn (SupportTicket $record): ?string => $record->assigned_to === null && $record->isOpen()
                        ? 'warning'
                        : null)
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('Waiting')
                    ->since()
                    ->sortable()
                    ->tooltip(fn (SupportTicket $record): string => $record->created_at?->format('d M Y, H:i') ?? ''),
            ])
            ->filters([
                Filter::make('open')
                    ->label('Still open')
                    ->query(fn (Builder $query): Builder => $query->open())
                    ->toggle()
                    ->default(),

                SelectFilter::make('kind')->label('Type')->options(TicketKind::class),
                SelectFilter::make('status')->options(TicketStatus::class)->multiple(),
                SelectFilter::make('category')->options(TicketCategory::class)->multiple(),
                SelectFilter::make('priority')->options(TicketPriority::class)->multiple(),

                Filter::make('unassigned')
                    ->label('Nobody has picked it up')
                    ->query(fn (Builder $query): Builder => $query->open()->unassigned())
                    ->toggle(),

                SelectFilter::make('assigned_to')
                    ->label('Assigned to')
                    ->options(fn (): array => User::query()
                        ->whereIn('role', ['super_admin', 'editor'])
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all()),
            ])
            ->recordActions([
                ViewAction::make()->label('Open'),

                Action::make('assign_to_me')
                    ->label('Take it')
                    ->icon('heroicon-o-hand-raised')
                    ->color('info')
                    ->visible(fn (SupportTicket $record): bool => $record->isOpen()
                        && $record->assigned_to !== Auth::id())
                    ->action(fn (SupportTicket $record) => $record->update([
                        'assigned_to' => Auth::id(),
                        // Picking one up is the moment it stops being new.
                        'status' => $record->status === TicketStatus::New
                            ? TicketStatus::Open
                            : $record->status,
                    ])),

                Action::make('resolve')
                    ->label('Resolve')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn (SupportTicket $record): bool => $record->isOpen())
                    ->form([
                        Textarea::make('resolution_note')
                            ->label('What was done')
                            ->required()
                            ->rows(3)
                            ->helperText('Kept on the ticket. Write it for whoever reads this in six months, not for yourself today.'),
                    ])
                    ->action(fn (SupportTicket $record, array $data) => $record->update([
                        'status' => TicketStatus::Resolved,
                        'resolved_at' => now(),
                        'resolved_by' => Auth::id(),
                        'resolution_note' => $data['resolution_note'],
                    ])),
            ])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])])
            // Worst first, then oldest: priority alone lets today's urgent
            // jump last week's, age alone buries an urgent one.
            ->modifyQueryUsing(fn (Builder $query) => $query->inQueueOrder())
            ->persistFiltersInSession()
            ->emptyStateIcon('heroicon-o-lifebuoy')
            ->emptyStateHeading('Nothing waiting')
            ->emptyStateDescription('Support requests and reports from the app arrive here.');
    }

    public static function getRelations(): array
    {
        return [
            MessagesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSupportTickets::route('/'),
            'view' => ViewSupportTicket::route('/{record}'),
        ];
    }

    /** What is open, which is the only number worth a badge here. */
    public static function getNavigationBadge(): ?string
    {
        $open = SupportTicket::query()->open()->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return SupportTicket::query()->open()->where('priority', TicketPriority::Urgent)->exists()
            ? 'danger'
            : 'warning';
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->role?->isStaff() ?? false;
    }

    /** Tickets arrive from people; staff answer them rather than file them. */
    public static function canCreate(): bool
    {
        return false;
    }
}
