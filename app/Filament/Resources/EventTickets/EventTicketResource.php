<?php

namespace App\Filament\Resources\EventTickets;

use App\Enums\BookingStatus;
use App\Filament\Resources\EventTickets\Pages\ListEventTickets;
use App\Filament\Support\DevoteeSearch;
use App\Models\EventRegistration;
use App\Support\DevotionalClock;
use App\Support\Payments\Payments;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Every "I'll join" and every paid ticket for temple events: who is coming,
 * what they paid, whether the gate received them, and recording a refund
 * made in the gateway's dashboard.
 */
class EventTicketResource extends Resource
{
    protected static ?string $model = EventRegistration::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-musical-note';

    protected static string|\UnitEnum|null $navigationGroup = 'Bookings & Counter';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Event tickets';

    protected static ?string $modelLabel = 'event ticket';

    protected static ?string $slug = 'finance/event-tickets';

    public static function canAccess(): bool
    {
        return Auth::user()?->role?->isStaff() ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['event:id,title,type', 'temple:id,name,city', 'payment:id,status,gateway,gateway_payment_id']))
            ->searchPlaceholder('Name, phone or reference')
            ->columns([
                TextColumn::make('occurs_on')->label('Day')->date('D, d M Y')->sortable(),
                TextColumn::make('event.title')->label('Event')->weight('medium')->wrap()
                    ->description(fn (EventRegistration $r): ?string => $r->temple?->name)->searchable(),
                TextColumn::make('devotee_name')->label('For')->searchable(query: DevoteeSearch::query())->description(fn (EventRegistration $r): ?string => $r->devotee_phone),
                TextColumn::make('people')->numeric()->alignCenter(),
                TextColumn::make('reference')->fontFamily('mono')->copyable()->searchable(),
                TextColumn::make('amount_paise')->label('Paid')->state(fn (EventRegistration $r): string => $r->amountLabel())
                    ->description(fn (EventRegistration $r): ?string => $r->payment ? ucfirst($r->payment->status).($r->payment->gateway_payment_id ? ' · '.$r->payment->gateway_payment_id : '') : null)
                    ->alignEnd()->sortable(),
                TextColumn::make('status')->badge()->sortable()
                    ->description(fn (EventRegistration $r): ?string => $r->isVerified() ? 'at '.$r->verified_at?->timezone(DevotionalClock::timezone())->format('d M, H:i') : $r->cancel_reason),
                TextColumn::make('settlement_id')->label('Settled')->state(fn (EventRegistration $r): string => $r->settlement_id ? 'Yes' : '—')->toggleable(),
            ])
            ->filters([
                Filter::make('paid')->label('Paid tickets only')->query(fn (Builder $query) => $query->where('amount_paise', '>', 0))->toggle(),
                Filter::make('upcoming')->label('Today and ahead')->query(fn (Builder $query) => $query->whereDate('occurs_on', '>=', DevotionalClock::now()->toDateString()))->toggle(),
                SelectFilter::make('status')->options(BookingStatus::class)->multiple(),
                SelectFilter::make('temple')->relationship('temple', 'name')->searchable()->preload(),
            ])
            ->recordActions([
                Action::make('refunded')
                    ->label('Mark refunded')
                    ->icon('heroicon-o-receipt-refund')
                    ->color('warning')
                    ->visible(fn (EventRegistration $r): bool => $r->payment?->isPaid() === true && $r->status !== BookingStatus::Refunded && (Auth::user()?->canManageUsers() ?? false))
                    ->requiresConfirmation()
                    ->modalDescription(fn (EventRegistration $r): string => 'Only after refunding it in the payment gateway. This records it: the payment reads refunded and the ticket is void.'
                        .($r->settlement_id ? ' It was already settled with the temple: recover it from the temple\'s next payout.' : ''))
                    ->action(function (EventRegistration $r): void {
                        app(Payments::class)->refunded($r->payment);
                        Notification::make()->title('Marked refunded.')->success()->send();
                    }),
            ])
            ->defaultSort('occurs_on', 'desc')
            ->emptyStateIcon('heroicon-o-musical-note')
            ->emptyStateHeading('No event tickets yet')
            ->emptyStateDescription('They arrive when a temple switches "Devotees can join in the app" on for an event.');
    }

    public static function getPages(): array
    {
        return ['index' => ListEventTickets::route('/')];
    }
}
