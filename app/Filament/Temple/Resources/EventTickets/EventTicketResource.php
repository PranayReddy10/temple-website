<?php

namespace App\Filament\Temple\Resources\EventTickets;

use App\Enums\BookingStatus;
use App\Filament\Support\DevoteeSearch;
use App\Filament\Temple\Resources\EventTickets\Pages\ListEventTickets;
use App\Models\EventRegistration;
use App\Models\TempleEvent;
use App\Support\DevotionalClock;
use App\Support\Events\EventRegistrations;
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
use Illuminate\Validation\ValidationException;
use UnitEnum;

/**
 * Who is coming to the temple's events: the trust app's Attendees screen.
 * Every registration and ticket, by day, with who has been received.
 */
class EventTicketResource extends Resource
{
    protected static ?string $model = EventRegistration::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-musical-note';

    protected static string|UnitEnum|null $navigationGroup = 'Counter';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Event attendees';

    protected static ?string $modelLabel = 'event ticket';

    protected static ?string $slug = 'event-tickets';

    public static function canAccess(): bool
    {
        return Auth::user()?->isTempleAdmin() ?? false;
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

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('temple_id', Auth::user()?->approvedTempleIds() ?? []);
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return static::getEloquentQuery();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['event:id,title,type', 'temple:id,name']))
            ->searchPlaceholder('Name, phone or reference')
            ->columns([
                TextColumn::make('occurs_on')->label('Day')->date('D, d M Y')->sortable(),
                TextColumn::make('event.title')->label('Event')->weight('medium')->wrap()
                    ->description(fn (EventRegistration $r): ?string => count(Auth::user()?->approvedTempleIds() ?? []) > 1 ? $r->temple?->name : null),
                TextColumn::make('devotee_name')->label('For')->searchable(query: DevoteeSearch::query())->description(fn (EventRegistration $r): ?string => $r->devotee_phone),
                TextColumn::make('people')->numeric()->alignCenter(),
                TextColumn::make('reference')->fontFamily('mono')->copyable()->searchable(),
                TextColumn::make('amount_paise')->label('Paid')->state(fn (EventRegistration $r): string => $r->amountLabel())->alignEnd(),
                TextColumn::make('status')->badge()
                    ->description(fn (EventRegistration $r): ?string => $r->isVerified() ? 'at '.$r->verified_at?->timezone(DevotionalClock::timezone())->format('d M, g:i A') : $r->cancel_reason),
            ])
            ->filters([
                Filter::make('today')->label('Today')->toggle()
                    ->query(fn (Builder $q) => $q->whereDate('occurs_on', DevotionalClock::now()->toDateString())),
                Filter::make('upcoming')->label('Today and ahead')->toggle()->default()
                    ->query(fn (Builder $q) => $q->whereDate('occurs_on', '>=', DevotionalClock::now()->toDateString())),
                SelectFilter::make('temple_event_id')->label('Event')->searchable()
                    ->options(fn (): array => TempleEvent::query()->whereIn('temple_id', Auth::user()?->approvedTempleIds() ?? [])
                        ->whereHas('registrations')->orderByDesc('starts_on')->limit(200)->pluck('title', 'id')->all()),
                SelectFilter::make('status')->options(BookingStatus::class)->multiple(),
            ])
            ->recordActions([
                Action::make('receive')
                    ->label('Mark received')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (EventRegistration $r): bool => $r->status === BookingStatus::Confirmed)
                    ->requiresConfirmation()
                    ->modalDescription(fn (EventRegistration $r): string => $r->devotee_name.' · '.$r->people.' '.str('person')->plural($r->people).' · '.$r->event?->title)
                    ->action(function (EventRegistration $r): void {
                        try {
                            app(EventRegistrations::class)->verify($r, Auth::user());
                            Notification::make()->title('Received')->success()->send();
                        } catch (ValidationException $e) {
                            Notification::make()->title('Not received')->body(collect($e->errors())->flatten()->first())->warning()->send();
                        }
                    }),
            ])
            ->defaultSort('occurs_on')
            ->emptyStateIcon('heroicon-o-musical-note')
            ->emptyStateHeading('No event tickets yet')
            ->emptyStateDescription('Switch on "Devotees can join in the app" for an event, and their registrations appear here.');
    }

    public static function getPages(): array
    {
        return ['index' => ListEventTickets::route('/')];
    }
}
