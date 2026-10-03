<?php

namespace App\Filament\Support;

use App\Models\TempleSettlement;
use App\Support\DevotionalClock;
use App\Support\Finance\Settlements;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Settlements to temples, as staff pay them and a temple reads them.
 *
 * Staff mark a settlement paid with its bank reference, or cancel one still
 * waiting so its bookings go into the next; a temple's team only reads, and
 * never sees another temple's.
 */
class SettlementTable
{
    public static function configure(Table $table, bool $staff = false): Table
    {
        return $table
            ->searchPlaceholder('Temple, town, reference or UTR')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['temple:id,name,city', 'payer:id,name']))
            ->columns([
                // The settlement's reference, or the bank's UTR once paid.
                TextColumn::make('reference')->label('Reference')->fontFamily('mono')->copyable()
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(fn (Builder $w) => $w
                        ->where('reference', 'like', '%'.$search.'%')->orWhere('transaction_ref', 'like', '%'.$search.'%'))),
                // The temple's name or its town.
                TextColumn::make('temple.name')->label('Temple')->weight('medium')->description(fn (TempleSettlement $s): ?string => $s->temple?->city)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas('temple', fn (Builder $t) => $t
                        ->where('name', 'like', '%'.$search.'%')->orWhere('city', 'like', '%'.$search.'%'))),
                TextColumn::make('period_to')->label('Seva days')->state(fn (TempleSettlement $s): string => $s->periodLabel())->sortable(),
                TextColumn::make('bookings_count')->label('Covers')->state(fn (TempleSettlement $s): string => $s->itemsLabel())->wrap(),
                TextColumn::make('gross_paise')->label('Paid by devotees')->state(fn (TempleSettlement $s): string => TempleSettlement::rupees($s->gross_paise))->alignEnd(),
                TextColumn::make('fee_paise')->label('Platform fee')->state(fn (TempleSettlement $s): string => TempleSettlement::rupees($s->fee_paise))
                    ->description(fn (TempleSettlement $s): string => rtrim(rtrim(number_format((float) $s->fee_percent, 2), '0'), '.').'%')->alignEnd(),
                TextColumn::make('net_paise')->label('To temple')->state(fn (TempleSettlement $s): string => TempleSettlement::rupees($s->net_paise))->weight('bold')->alignEnd()->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => TempleSettlement::STATUSES[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        TempleSettlement::PAID => 'success', TempleSettlement::PENDING => 'warning', default => 'gray',
                    })
                    ->description(fn (TempleSettlement $s): ?string => match ($s->status) {
                        TempleSettlement::PAID => collect([
                            $s->paid_at?->timezone(DevotionalClock::timezone())->format('d M Y'),
                            $s->method ? strtoupper($s->method) : null,
                            $s->transaction_ref,
                        ])->filter()->implode(' · '),
                        TempleSettlement::CANCELLED => $s->cancel_reason,
                        default => null,
                    }),
                TextColumn::make('created_at')->label('Prepared')->since()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(TempleSettlement::STATUSES),
                SelectFilter::make('temple')->relationship('temple', 'name', fn (Builder $query) => $staff ? $query : $query->whereIn('temples.id', Auth::user()?->approvedTempleIds() ?? []))->searchable()->preload(),
            ])
            ->recordActions([
                self::detailsAction($staff),
                ...($staff ? [self::paidAction(), ActionGroup::make([self::cancelAction()])] : []),
            ])
            ->defaultSort('id', 'desc')
            ->emptyStateIcon('heroicon-o-banknotes')
            ->emptyStateHeading('No settlements yet')
            ->emptyStateDescription($staff
                ? 'Prepare one from Temple balances once a temple has paid bookings whose day has passed.'
                : 'Payouts for your paid seva bookings appear here once the platform prepares them.');
    }

    public static function detailsAction(bool $staff): Action
    {
        return Action::make('details')
            ->label('Open')
            ->icon('heroicon-o-document-text')
            ->color('gray')
            ->modalHeading(fn (TempleSettlement $s): string => 'Settlement '.$s->reference)
            ->modalDescription(fn (TempleSettlement $s): string => ($s->temple?->name ?? '').' · '.$s->periodLabel())
            ->modalContent(fn (TempleSettlement $s): View => view('filament.finance.settlement', [
                'settlement' => $s->load([
                    'temple', 'creator', 'payer',
                    'bookings' => fn ($q) => $q->with('puja:id,name')->orderBy('booked_for'),
                    'tickets' => fn ($q) => $q->with('event:id,title')->orderBy('occurs_on'),
                    'donations' => fn ($q) => $q->with('devotee:id,name')->orderBy('paid_on'),
                ]),
                'payout' => $staff ? Settlements::payoutDetails($s) : null,
                'staff' => $staff,
            ]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close');
    }

    public static function paidAction(): Action
    {
        return Action::make('paid')
            ->label('Mark paid')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (TempleSettlement $s): bool => $s->isPending())
            ->modalHeading(fn (TempleSettlement $s): string => 'Paid '.TempleSettlement::rupees($s->net_paise).' to '.($s->temple?->name ?? 'the temple').'?')
            ->modalDescription('Only once the transfer has gone through. The temple sees the reference in its app and portal.')
            ->schema([
                Select::make('method')->label('Paid by')->options(TempleSettlement::METHODS)->default('bank')->native(false)->required(),
                TextInput::make('transaction_ref')->label('UTR / transaction or cheque number')->maxLength(80),
                DateTimePicker::make('paid_at')->label('Paid on')->native(false)->default(now())->maxDate(now()->addDay()),
                Textarea::make('note')->label('Note (the temple sees this)')->rows(2)->maxLength(500),
            ])
            ->action(function (TempleSettlement $s, array $data): void {
                try {
                    app(Settlements::class)->markPaid($s, $data['method'], $data['transaction_ref'] ?? null, Auth::user(), $data['paid_at'] ?? null, $data['note'] ?? null);
                } catch (ValidationException $e) {
                    Notification::make()->title(collect($e->errors())->flatten()->first())->danger()->send();

                    return;
                }

                Notification::make()->title('Settlement '.$s->reference.' marked paid.')->success()->send();
            });
    }

    public static function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label('Cancel settlement')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (TempleSettlement $s): bool => $s->isPending())
            ->modalDescription('Its bookings go back to the temple\'s balance, to be settled again. Only if nothing was transferred.')
            ->schema([Textarea::make('reason')->label('Reason')->rows(2)->maxLength(255)->required()])
            ->action(function (TempleSettlement $s, array $data): void {
                app(Settlements::class)->cancel($s, $data['reason']);
                Notification::make()->title('Settlement cancelled; its bookings are back in the balance.')->success()->send();
            });
    }
}
