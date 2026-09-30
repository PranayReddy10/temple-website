<?php

namespace App\Filament\Resources\TempleBalances;

use App\Enums\BookingStatus;
use App\Filament\Resources\TempleBalances\Pages\ListTempleBalances;
use App\Filament\Resources\TempleSettlements\TempleSettlementResource;
use App\Models\Temple;
use App\Models\TemplePayoutAccount;
use App\Models\TempleSettlement;
use App\Support\DevotionalClock;
use App\Support\Finance\Settlements;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * What the platform owes each temple for its seva bookings.
 *
 * One row per temple that takes paid bookings: ready to settle (paid, seva
 * day passed, in no settlement), paid for days still ahead, being paid out,
 * and paid to date. "Settle" gathers the ready bookings into a settlement to
 * transfer; the transfer is then recorded under Settlements.
 */
class TempleBalanceResource extends Resource
{
    protected static ?string $model = Temple::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-library';

    protected static string|\UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Temple balances';

    protected static ?string $modelLabel = 'temple balance';

    protected static ?string $pluralModelLabel = 'temple balances';

    protected static ?string $slug = 'finance/temple-balances';

    public static function canAccess(): bool
    {
        return Auth::user()?->canManageUsers() ?? false;
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
        $cutoff = app(Settlements::class)->defaultCutoff()->toDateString();
        $live = [BookingStatus::Confirmed->value, BookingStatus::Verified->value];

        return parent::getEloquentQuery()
            ->select(['temples.id', 'temples.name', 'temples.city', 'temples.slug'])
            ->with('payoutAccount')
            ->where(fn (Builder $q) => $q
                ->whereHas('pujaBookings', fn (Builder $b) => $b->where('amount_paise', '>', 0)->whereIn('status', $live))
                ->orWhereHas('payoutAccount')
                ->orWhereHas('settlements'))
            ->withCount(['pujaBookings as ready_count' => fn (Builder $q) => $q->settleable()->whereDate('booked_for', '<=', $cutoff)])
            ->withSum(['pujaBookings as ready_gross' => fn (Builder $q) => $q->settleable()->whereDate('booked_for', '<=', $cutoff)], 'amount_paise')
            ->withSum(['pujaBookings as ahead_gross' => fn (Builder $q) => $q->settleable()->whereDate('booked_for', '>', $cutoff)], 'amount_paise')
            ->withSum(['settlements as in_payout' => fn (Builder $q) => $q->pending()], 'net_paise')
            ->withSum(['settlements as paid_total' => fn (Builder $q) => $q->paid()], 'net_paise');
    }

    public static function table(Table $table): Table
    {
        $rupees = fn ($paise): string => TempleSettlement::rupees((int) $paise);
        $fee = fn (Temple $t): float => app(Settlements::class)->feePercentFor($t);

        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Temple')
                    ->weight('medium')
                    ->description(fn (Temple $t): ?string => $t->city)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('ready_gross')
                    ->label('Ready to settle')
                    ->state(fn (Temple $t): string => $rupees($t->ready_gross))
                    ->description(fn (Temple $t): string => $t->ready_count.' bookings · gross')
                    ->color(fn (Temple $t): string => (int) $t->ready_gross > 0 ? 'warning' : 'gray')
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('net_ready')
                    ->label('Temple gets')
                    ->state(function (Temple $t) use ($rupees, $fee): string {
                        $gross = (int) $t->ready_gross;

                        return $rupees($gross - Settlements::feeOf($gross, $fee($t)));
                    })
                    ->description(fn (Temple $t): string => 'after '.rtrim(rtrim(number_format($fee($t), 2), '0'), '.').'% fee')
                    ->weight('medium')
                    ->alignEnd(),

                TextColumn::make('ahead_gross')
                    ->label('Paid, day ahead')
                    ->state(fn (Temple $t): string => $rupees($t->ahead_gross))
                    ->tooltip('Paid by devotees for seva days still to come; settled after the day.')
                    ->color('gray')
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('in_payout')
                    ->label('Being paid')
                    ->state(fn (Temple $t): string => $rupees($t->in_payout))
                    ->color(fn (Temple $t): string => (int) $t->in_payout > 0 ? 'info' : 'gray')
                    ->alignEnd(),

                TextColumn::make('paid_total')
                    ->label('Paid to date')
                    ->state(fn (Temple $t): string => $rupees($t->paid_total))
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('payout')
                    ->label('Payout details')
                    ->badge()
                    ->state(fn (Temple $t): string => match (true) {
                        $t->payoutAccount === null || ! $t->payoutAccount->isComplete() => 'Missing',
                        $t->payoutAccount->isVerified() => 'Verified',
                        default => 'To verify',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'Verified' => 'success', 'To verify' => 'warning', default => 'danger',
                    })
                    ->description(fn (Temple $t): ?string => $t->payoutAccount?->summary()),
            ])
            ->filters([
                Filter::make('owed')
                    ->label('Owed now or being paid')
                    ->query(fn (Builder $query): Builder => $query->where(fn (Builder $q) => $q
                        ->whereHas('pujaBookings', fn (Builder $b) => $b->settleable()->whereDate('booked_for', '<=', app(Settlements::class)->defaultCutoff()->toDateString()))
                        ->orWhereHas('settlements', fn (Builder $s) => $s->pending())))
                    ->toggle()
                    ->default(),
                Filter::make('payout_unverified')
                    ->label('Payout details not verified')
                    ->query(fn (Builder $query): Builder => $query->whereDoesntHave('payoutAccount', fn (Builder $a) => $a->whereNotNull('verified_at')))
                    ->toggle(),
            ])
            ->recordActions([
                self::settleAction(),
                ActionGroup::make([
                    self::payoutAccountAction(),
                    self::verifyPayoutAction(),
                    Action::make('history')
                        ->label('Settlements')
                        ->icon('heroicon-o-queue-list')
                        ->url(fn (Temple $t): string => TempleSettlementResource::getUrl('index', ['filters' => ['temple' => ['value' => (string) $t->id]]])),
                ]),
            ])
            ->defaultSort('ready_gross', 'desc')
            ->persistFiltersInSession()
            ->emptyStateIcon('heroicon-o-building-library')
            ->emptyStateHeading('Nothing owed to temples')
            ->emptyStateDescription('Temples appear here once devotees pay for a seva booked in the app.');
    }

    public static function settleAction(): Action
    {
        return Action::make('settle')
            ->label('Settle')
            ->icon('heroicon-o-banknotes')
            ->color('success')
            ->visible(fn (Temple $t): bool => (int) $t->ready_gross > 0)
            ->modalHeading(fn (Temple $t): string => 'Settle with '.$t->name)
            ->modalDescription(function (Temple $t): string {
                $account = $t->payoutAccount;

                return match (true) {
                    $account === null || ! $account->isComplete() => 'This temple has not given payout details yet. You can prepare the settlement now; add the details before you transfer.',
                    ! $account->isVerified() => 'Pays to: '.$account->summary().'. These details are NOT verified yet: call the temple to confirm them before transferring.',
                    default => 'Pays to: '.$account->summary().' (verified).',
                };
            })
            ->schema([
                DatePicker::make('up_to')
                    ->label('Settle seva days up to')
                    ->native(false)
                    ->default(fn () => app(Settlements::class)->defaultCutoff()->toDateString())
                    ->maxDate(fn () => DevotionalClock::now()->toDateString())
                    ->required()
                    ->helperText('Every paid booking up to this day that is not settled yet is included. Days still ahead are left for the next settlement.'),
                Textarea::make('note')->label('Note (the temple sees this)')->rows(2)->maxLength(500),
            ])
            ->modalSubmitActionLabel('Prepare settlement')
            ->action(function (Temple $t, array $data): void {
                try {
                    $s = app(Settlements::class)->create($t, $data['up_to'], Auth::user(), $data['note'] ?? null);
                } catch (ValidationException $e) {
                    Notification::make()->title(collect($e->errors())->flatten()->first())->danger()->send();

                    return;
                }

                Notification::make()
                    ->title('Settlement '.$s->reference.' prepared: '.TempleSettlement::rupees($s->net_paise).' to pay')
                    ->body($s->bookings_count.' bookings, '.$s->periodLabel().'. Transfer it, then mark it paid with the UTR under Settlements.')
                    ->success()
                    ->send();
            });
    }

    public static function payoutAccountAction(): Action
    {
        return Action::make('payoutAccount')
            ->label('Payout details & fee')
            ->icon('heroicon-o-credit-card')
            ->color('gray')
            ->fillForm(fn (Temple $t): array => [
                'account_name' => $t->payoutAccount?->account_name,
                'ifsc' => $t->payoutAccount?->ifsc,
                'bank_name' => $t->payoutAccount?->bank_name,
                'upi_id' => $t->payoutAccount?->upi_id,
                'platform_fee_percent' => $t->payoutAccount?->platform_fee_percent,
                'verified' => $t->payoutAccount?->isVerified() ?? false,
            ])
            ->schema([
                Section::make('Where the temple is paid')
                    ->columns(2)
                    ->schema([
                        TextInput::make('account_name')->label('Account holder')->maxLength(120),
                        TextInput::make('account_number')
                            ->label('Account number')
                            ->regex('/^[0-9]{6,20}$/')
                            ->placeholder(fn (Temple $t): string => $t->payoutAccount?->maskedAccountNumber() ?? 'Digits only')
                            ->helperText('Leave blank to keep the number on file.'),
                        TextInput::make('ifsc')->label('IFSC')->regex('/^[A-Za-z]{4}0[A-Za-z0-9]{6}$/')->maxLength(11),
                        TextInput::make('bank_name')->label('Bank and branch')->maxLength(120),
                        TextInput::make('upi_id')->label('UPI ID')->regex('/^[A-Za-z0-9.\-_]{2,256}@[A-Za-z]{2,64}$/')->placeholder('name@bank'),
                        Toggle::make('verified')->label('Details verified with the temple')->inline(false),
                    ]),
                Section::make('Platform fee')
                    ->schema([
                        TextInput::make('platform_fee_percent')
                            ->label('Fee for this temple (%)')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->step(0.01)
                            ->placeholder(fn (): string => 'Default: '.app(Settlements::class)->defaultFeePercent().'%')
                            ->helperText('Blank uses the default from Settings → Payment gateways. Applies to settlements prepared from now on.'),
                    ]),
            ])
            ->action(function (Temple $t, array $data): void {
                /** @var TemplePayoutAccount $account */
                $account = $t->payoutAccount()->firstOrNew();
                $account->fill([
                    'account_name' => $data['account_name'] ?? null,
                    'ifsc' => filled($data['ifsc'] ?? null) ? strtoupper($data['ifsc']) : null,
                    'bank_name' => $data['bank_name'] ?? null,
                    'upi_id' => $data['upi_id'] ?? null,
                    'platform_fee_percent' => filled($data['platform_fee_percent'] ?? null) ? $data['platform_fee_percent'] : null,
                    'updated_by' => Auth::id(),
                ]);
                if (filled($data['account_number'] ?? null)) {
                    $account->account_number = $data['account_number'];
                }
                $account->save();

                $account->forceFill($data['verified'] && $account->isComplete()
                    ? ['verified_at' => $account->verified_at ?? now(), 'verified_by' => $account->verified_by ?? Auth::id()]
                    : ['verified_at' => null, 'verified_by' => null])->saveQuietly();

                Notification::make()->title('Payout details saved.')->success()->send();
            });
    }

    public static function verifyPayoutAction(): Action
    {
        return Action::make('verifyPayout')
            ->label('Mark payout details verified')
            ->icon('heroicon-o-shield-check')
            ->color('success')
            ->visible(fn (Temple $t): bool => $t->payoutAccount?->isComplete() === true && ! $t->payoutAccount->isVerified())
            ->requiresConfirmation()
            ->modalDescription(fn (Temple $t): string => $t->payoutAccount?->summary().'. Only after confirming these with the temple, for example by calling the number on their account.')
            ->action(function (Temple $t): void {
                $t->payoutAccount->forceFill(['verified_at' => now(), 'verified_by' => Auth::id()])->saveQuietly();
                Notification::make()->title('Payout details verified.')->success()->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTempleBalances::route('/'),
        ];
    }
}
