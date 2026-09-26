<?php

namespace App\Filament\Support;

use App\Enums\BookingStatus;
use App\Models\PujaBooking;
use App\Support\Bookings\PujaBookings;
use App\Support\DevotionalClock;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * The bookings list, as the temple's counter and the admin both see it.
 *
 * One definition for three screens (the temple portal's list, the list
 * inside a temple, and the admin's queue across every temple): who booked,
 * for which seva and day, how many people, what they paid, and the
 * reference and code to receive them by. Verifying and cancelling go
 * through the same service the scanner uses, so a booking verified from
 * the list is refused a second time at the counter just the same.
 */
class PujaBookingTable
{
    /**
     * @param  bool  $showTemple  false inside one temple, where it is obvious
     * @param  bool  $staff  the admin sees payment details and may record refunds
     */
    public static function configure(Table $table, bool $showTemple = true, bool $staff = false): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['puja:id,name,kind,starts_at', 'temple:id,name,city', 'devotee:id,name,email,phone', 'payment:id,uuid,status,gateway,gateway_payment_id', 'verifier:id,name']))
            ->columns([
                TextColumn::make('booked_for')
                    ->label('Day')
                    ->date('D, d M Y')
                    ->description(fn (PujaBooking $record): ?string => $record->puja?->starts_at ? substr((string) $record->puja->starts_at, 0, 5) : null)
                    ->sortable(),

                TextColumn::make('puja.name')
                    ->label('Seva')
                    ->weight('medium')
                    ->wrap()
                    ->description(fn (PujaBooking $record): ?string => $showTemple ? $record->temple?->name : $record->puja?->kind?->getLabel())
                    ->searchable(),

                TextColumn::make('devotee_name')
                    ->label('Booked by')
                    ->searchable()
                    ->description(fn (PujaBooking $record): string => collect([
                        $record->devotee_phone,
                        $record->gotram ? 'Gotram '.$record->gotram : null,
                        $record->nakshatram,
                    ])->filter()->implode(' · ')),

                TextColumn::make('people')->label('People')->numeric()->alignCenter(),

                TextColumn::make('reference')
                    ->label('Reference')
                    ->fontFamily('mono')
                    ->copyable()
                    ->searchable(),

                TextColumn::make('amount_paise')
                    ->label('Paid')
                    ->state(fn (PujaBooking $record): string => $record->amountLabel())
                    ->description(fn (PujaBooking $record): ?string => $record->payment === null
                        ? null
                        : ucfirst($record->payment->status).($staff && $record->payment->gateway_payment_id ? ' · '.$record->payment->gateway_payment_id : ''))
                    ->alignEnd(),

                TextColumn::make('status')
                    ->badge()
                    ->sortable()
                    ->description(fn (PujaBooking $record): ?string => $record->isVerified()
                        ? 'by '.($record->verifier?->name ?? 'the temple').' · '.$record->verified_at?->timezone(DevotionalClock::timezone())->format('d M, H:i')
                        : $record->cancel_reason),

                TextColumn::make('created_at')->label('Booked')->since()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('today')
                    ->label('Today')
                    ->query(fn (Builder $query): Builder => $query->forDay(DevotionalClock::now()->toDateString()))
                    ->toggle(),
                Filter::make('upcoming')
                    ->label('Today and ahead')
                    ->query(fn (Builder $query): Builder => $query->upcoming())
                    ->toggle()
                    ->default(),
                Filter::make('live')
                    ->label('Confirmed or verified only')
                    ->query(fn (Builder $query): Builder => $query->live())
                    ->toggle(),
                SelectFilter::make('status')->options(BookingStatus::class)->multiple(),
                ...($showTemple ? [SelectFilter::make('temple')->relationship('temple', 'name')->searchable()->preload()] : []),
            ])
            ->recordActions([
                self::detailsAction(),
                self::verifyAction(),
                ActionGroup::make([
                    self::cancelAction($staff ? 'staff' : 'temple'),
                    ...($staff ? [self::refundedAction()] : []),
                ]),
            ])
            ->defaultSort('booked_for', 'asc')
            ->persistFiltersInSession()
            ->emptyStateIcon('heroicon-o-ticket')
            ->emptyStateHeading('No bookings yet')
            ->emptyStateDescription('Bookings devotees make in the app arrive here. Switch "Devotees can book this in the app" on for a seva to start taking them.');
    }

    /** The booking in full, with the code as the devotee's phone shows it. */
    public static function detailsAction(): Action
    {
        return Action::make('details')
            ->label('Open')
            ->icon('heroicon-o-ticket')
            ->color('gray')
            ->modalHeading(fn (PujaBooking $record): string => $record->reference)
            ->modalDescription(fn (PujaBooking $record): string => $record->summary())
            ->modalContent(fn (PujaBooking $record): View => view('filament.bookings.details', ['booking' => $record->load(['puja', 'temple', 'devotee', 'payment', 'verifier'])]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close');
    }

    public static function verifyAction(): Action
    {
        return Action::make('verify')
            ->label('Mark received')
            ->icon('heroicon-o-check-badge')
            ->color('success')
            ->visible(fn (PujaBooking $record): bool => $record->isConfirmed())
            ->requiresConfirmation()
            ->modalHeading(fn (PujaBooking $record): string => 'Receive '.$record->devotee_name.'?')
            ->modalDescription('Only while they are here with you. The booking is marked verified with your name, and its code stops working: a second scan is refused.')
            ->action(function (PujaBooking $record): void {
                try {
                    $result = app(PujaBookings::class)->verify($record, Auth::user());
                } catch (AuthorizationException|ValidationException $e) {
                    Notification::make()->title($e instanceof ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()
                    ->title($result['outcome'] === PujaBookings::VERIFIED
                        ? $record->devotee_name.' is marked as received. The code is now used.'
                        : 'This booking was already verified.')
                    ->success()
                    ->send();
            });
    }

    public static function cancelAction(string $by): Action
    {
        return Action::make('cancel')
            ->label('Cancel booking')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (PujaBooking $record): bool => in_array($record->status, [BookingStatus::PendingPayment, BookingStatus::Confirmed], true))
            ->schema([
                Textarea::make('reason')
                    ->label('Reason (the devotee sees this)')
                    ->rows(2)
                    ->maxLength(255)
                    ->required(),
            ])
            ->modalDescription(fn (PujaBooking $record): string => $record->isFree() || $record->payment?->isPaid() !== true
                ? 'The devotee is told, and the slot is freed.'
                : 'The devotee is told and the slot is freed. Their money is not returned by this: refund it in your payment gateway, then mark the booking refunded.')
            ->action(function (PujaBooking $record, array $data) use ($by): void {
                try {
                    app(PujaBookings::class)->cancel($record, $by, $data['reason']);
                } catch (ValidationException $e) {
                    Notification::make()->title(collect($e->errors())->flatten()->first())->danger()->send();

                    return;
                }

                Notification::make()->title('Booking cancelled.')->success()->send();
            });
    }

    /** Recorded after refunding in the gateway's own dashboard; nothing here moves money. */
    public static function refundedAction(): Action
    {
        return Action::make('refunded')
            ->label('Mark refunded')
            ->icon('heroicon-o-receipt-refund')
            ->color('warning')
            ->visible(fn (PujaBooking $record): bool => $record->payment?->isPaid() === true && $record->status !== BookingStatus::Refunded)
            ->requiresConfirmation()
            ->modalDescription('Only after the refund has been made in the payment gateway. This records it: the payment reads refunded and the booking is void.')
            ->action(function (PujaBooking $record): void {
                app(\App\Support\Payments\Payments::class)->refunded($record->payment);
                Notification::make()->title('Marked refunded.')->success()->send();
            });
    }
}
