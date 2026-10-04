<?php

namespace App\Filament\Temple\Resources\SupportTickets;

use App\Enums\TicketCategory;
use App\Enums\TicketKind;
use App\Filament\Temple\Resources\SupportTickets\Pages\ListSupportTickets;
use App\Filament\Temple\Resources\SupportTickets\Pages\ViewSupportTicket;
use App\Filament\Temple\TemplePortal;
use App\Models\SupportTicket;
use App\Models\Temple;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Questions and problems for our team, as in the trust app's Help & support:
 * the same queue staff answer in the admin panel, with their replies here.
 * A team member sees only the tickets they wrote.
 */
class SupportTicketResource extends Resource
{
    protected static ?string $model = SupportTicket::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-lifebuoy';

    protected static string|UnitEnum|null $navigationGroup = 'Help';

    protected static ?string $navigationLabel = 'Support';

    protected static ?string $modelLabel = 'support request';

    protected static ?string $slug = 'support';

    protected static ?string $recordRouteKeyName = 'reference';

    public static function canAccess(): bool
    {
        return Auth::user()?->isTempleAdmin() ?? false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('user_id', Auth::id() ?? 0);
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return static::getEloquentQuery();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Sent')->since()->sortable(),
                TextColumn::make('subject')->weight('medium')->wrap()->searchable()
                    ->description(fn (SupportTicket $t): ?string => $t->aboutLabel()),
                TextColumn::make('category')->badge()->color('gray'),
                TextColumn::make('status')->badge(),
                TextColumn::make('reference')->fontFamily('mono')->searchable(),
            ])
            ->recordUrl(fn (SupportTicket $t): string => static::getUrl('view', ['record' => $t]))
            ->defaultSort('created_at', 'desc')
            ->emptyStateIcon('heroicon-o-lifebuoy')
            ->emptyStateHeading('No support requests')
            ->emptyStateDescription('Ask our team anything: a correction to the temple\'s page, payments, a problem with the app.');
    }

    /**
     * A new request from this team member, about one of their own temples
     * if they choose one.
     *
     * @param  array{category?: ?string, subject: string, body: string, temple_id?: ?int}  $data
     */
    public static function open(User $user, array $data): SupportTicket
    {
        $ticket = new SupportTicket([
            'kind' => TicketKind::Support,
            'category' => $data['category'] ?? TicketCategory::Other,
            'subject' => $data['subject'],
            'body' => $data['body'],
            'reporter_name' => $user->name,
            'reporter_email' => $user->email,
            'source' => 'temple-portal',
        ]);
        $ticket->user_id = $user->getKey();

        if (filled($data['temple_id'] ?? null)) {
            // Only a temple this account runs.
            $temple = TemplePortal::temples()->firstWhere('id', (int) $data['temple_id']);
            abort_if(! $temple instanceof Temple, 403);
            $ticket->about()->associate($temple);
        }

        $ticket->save();

        return $ticket;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSupportTickets::route('/'),
            'view' => ViewSupportTicket::route('/{record}'),
        ];
    }
}
