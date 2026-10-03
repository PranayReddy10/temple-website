<?php

namespace App\Filament\Resources\TempleBalances;

use App\Enums\BookingStatus;
use App\Filament\Resources\PaymentVerifications\PaymentVerificationResource;
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
                ->orWhereHas('eventRegistrations', fn (Builder $b) => $b->where('amount_paise', '>', 0)->whereIn('status', $live))
                ->orWhereHas('donations', fn (Builder $b) => $b->paid())
                ->orWhereHas('payoutAccount')
                ->orWhereHas('settlements'))
            // Seva bookings (b), event tickets (t) and hundi gifts (d), each
            // ready (dated up to yesterday) and ahead.
            ->withCount(['pujaBookings as rb_count' => fn (Builder $q) => $q->settleable()->whereDate('booked_for', '<=', $cutoff)])
            ->withCount(['eventRegistrations as rt_count' => fn (Builder $q) => $q->settleable()->whereDate('occurs_on', '<=', $cutoff)])
            ->withCount(['donations as rd_count' => fn (Builder $q) => $q->settleable()->whereDate('paid_on', '<=', $cutoff)])
            ->withSum(['pujaBookings as rb_gross' => fn (Builder $q) => $q->settleable()->whereDate('booked_for', '<=', $cutoff)], 'amount_paise')
            ->withSum(['eventRegistrations as rt_gross' => fn (Builder $q) => $q->settleable()->whereDate('occurs_on', '<=', $cutoff)], 'amount_paise')
            ->withSum(['donations as rd_gross' => fn (Builder $q) => $q->settleable()->whereDate('paid_on', '<=', $cutoff)], 'amount_paise')
            ->withSum(['pujaBookings as ab_gross' => fn (Builder $q) => $q->settleable()->whereDate('booked_for', '>', $cutoff)], 'amount_paise')
            ->withSum(['eventRegistrations as at_gross' => fn (Builder $q) => $q->settleable()->whereDate('occurs_on', '>', $cutoff)], 'amount_paise')
            ->withSum(['donations as ad_gross' => fn (Builder $q) => $q->settleable()->whereDate('paid_on', '>', $cutoff)], 'amount_paise')
            ->withSum(['settlements as in_payout' => fn (Builder $q) => $q->pending()], 'net_paise')
            ->withSum(['settlements as paid_total' => fn (Builder $q) => $q->paid()], 'net_paise');
    }

    public static function table(Table $table): Table
    {
        $rupees = fn ($paise): string => TempleSettlement::rupees((int) $paise);
        $fee = fn (Temple $t): float => app(Settlements::class)->feePercentFor($t);
        $ready = fn (Temple $t): int => (int) $t->rb_gross + (int) $t->rt_gross + (int) $t->rd_gross;
        $ahead = fn (Temple $t): int => (int) $t->ab_gross + (int) $t->at_gross + (int) $t->ad_gross;
        $sortBy = fn (string $cols) => fn (Builder $query, string $direction): Builder => $query->orderByRaw('('.collect(explode(',', $cols))->map(fn ($c) => "coalesce({$c}, 0)")->implode(' + ').') '.($direction === 'asc' ? 'asc' : 'desc'));

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
                    ->state(fn (Temple $t): string => $rupees($ready($t)))
                    ->description(fn (Temple $t): string => collect([
                        $t->rb_count ? $t->rb_count.' sevas' : null,
                        $t->rt_count ? $t->rt_count.' tickets' : null,
                        $t->rd_count ? $t->rd_count.' hundi' : null,
                    ])->filter()->implode(' · ') ?: 'gross')
                    ->color(fn (Temple $t): string => $ready($t) > 0 ? 'warning' : 'gray')
                    ->alignEnd()
                    ->sortable(query: $sortBy('rb_gross,rt_gross,rd_gross')),

                TextColumn::make('net_ready')
                    ->label('Temple gets')
                    ->state(function (Temple $t) use ($rupees, $ready): string {
                        $services = (int) $t->rb_gross + (int) $t->rt_gross;

                        return $rupees($ready($t) - app(Settlements::class)->feeFor($t, $services, (int) $t->rd_gross));
                    })
                    ->description(fn (Temple $t): string => 'after '.rtrim(rtrim(number_format($fee($t), 2), '0'), '.').'% fee'.((int) $t->rd_gross > 0 ? ' ('.rtrim(rtrim(number_format(app(Settlements::class)->donationFeePercent(), 2), '0'), '.').'% on hundi)' : ''))
                    ->weight('medium')
                    ->alignEnd(),

                TextColumn::make('ahead_gross')
                    ->label('Paid, day ahead')
                    ->state(fn (Temple $t): string => $rupees($ahead($t)))
                    ->tooltip('Paid for seva and event days still to come, and today\'s hundi gifts.')
                    ->color('gray')
                    ->alignEnd()
                    ->sortable(query: $sortBy('ab_gross,at_gross,ad_gross')),

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
                    ->label('Payments')
                    ->badge()
                    ->state(fn (Temple $t): string => match ($t->payoutAccount?->kycStatus() ?? 'missing') {
                        'approved' => 'Approved',
                        'pending' => 'To check',
                        'rejected' => 'Rejected',
                        default => 'Missing',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'Approved' => 'success', 'To check' => 'warning', default => 'danger',
                    })
                    ->description(fn (Temple $t): ?string => $t->payoutAccount?->summary()),
            ])
            ->filters([
                Filter::make('owed')
                    ->label('Unsettled money or being paid')
                    ->query(fn (Builder $query): Builder => $query->where(fn (Builder $q) => $q
                        ->whereHas('pujaBookings', fn (Builder $b) => $b->settleable())
                        ->orWhereHas('eventRegistrations', fn (Builder $b) => $b->settleable())
                        ->orWhereHas('donations', fn (Builder $b) => $b->settleable())
                        ->orWhereHas('settlements', fn (Builder $s) => $s->pending())))
                    ->toggle()
                    ->default(),
                Filter::make('kyc_to_check')
                    ->label('Verification waiting to be checked')
                    ->query(fn (Builder $query): Builder => $query->whereHas('payoutAccount', fn (Builder $a) => $a
                        ->whereNotNull('kyc_submitted_at')->whereNull('verified_at')->whereNull('rejection_reason')))
                    ->toggle(),
                Filter::make('payout_unverified')
                    ->label('Payout details not verified')
                    ->query(fn (Builder $query): Builder => $query->whereDoesntHave('payoutAccount', fn (Builder $a) => $a->whereNotNull('verified_at')))
                    ->toggle(),
            ])
            ->recordActions([
                self::settleAction(),
                ActionGroup::make([
                    self::payoutAccountAction(),
                    self::reviewPaymentsAction(),
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
            ->visible(fn (Temple $t): bool => (int) $t->rb_gross + (int) $t->rt_gross + (int) $t->rd_gross + (int) $t->ab_gross + (int) $t->at_gross + (int) $t->ad_gross > 0)
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
                    // Everything unsettled by default, advance bookings included.
                    ->default(fn (Temple $record) => (app(Settlements::class)->latestUnsettledDay($record) ?? DevotionalClock::now())->toDateString())
                    ->required()
                    ->helperText(fn (Temple $record): string => 'Every paid seva booking, event ticket and hundi gift up to this day that is not settled yet is included: '
                        .TempleSettlement::rupees((int) $record->rb_gross + (int) $record->rt_gross + (int) $record->rd_gross).' up to yesterday'
                        .(((int) $record->ab_gross + (int) $record->at_gross + (int) $record->ad_gross) > 0 ? ' and '.TempleSettlement::rupees((int) $record->ab_gross + (int) $record->at_gross + (int) $record->ad_gross).' for today and days ahead' : '')
                        .'. Choose an earlier day to leave advance bookings for later. Once settled, a booking can no longer be cancelled.'),
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
                    ->body($s->itemsLabel().', '.$s->periodLabel().'. Transfer it, then mark it paid with the UTR under Settlements.')
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
                'accepts_donations' => (bool) Temple::query()->whereKey($t->getKey())->value('accepts_donations'),
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
                        Toggle::make('verified')
                            ->label('Approved: bank details and documents checked')
                            ->helperText('Takes effect only once the owner has sent the Aadhaar, temple proof and photo from the Trust app.')
                            ->inline(false),
                    ]),
                Section::make('Online hundi')
                    ->schema([
                        Toggle::make('accepts_donations')
                            ->label('Accept online hundi gifts')
                            ->helperText('Usually switched on by the temple\'s owner in the Darshan Saathi Trust app.'),
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
                $wasApproved = $account->exists && $account->isVerified();
                $account->save();

                $account->forceFill($data['verified'] && $account->isComplete() && $account->hasKyc()
                    ? ['verified_at' => $account->verified_at ?? now(), 'verified_by' => $account->verified_by ?? Auth::id()]
                    : ['verified_at' => null, 'verified_by' => null])->saveQuietly();

                if ($wasApproved !== $account->isVerified()) {
                    $account->recordEvent($account->isVerified() ? 'approved' : 'approval_removed', $account->isVerified() ? null : 'Changed in Temple balances → Payout details.', Auth::id());
                }

                $hundi = (bool) ($data['accepts_donations'] ?? false) && $account->refresh()->canReceiveMoney();
                Temple::query()->whereKey($t->getKey())->first()?->forceFill(['accepts_donations' => $hundi])->save();

                Notification::make()->title('Payout details saved.')->success()->send();
                if (($data['accepts_donations'] ?? false) && ! $hundi) {
                    Notification::make()->title('Online hundi left off')->body('It opens once the bank details and the verification documents are approved.')->warning()->send();
                }
            });
    }

    /** The documents and the decision live on their own page. */
    public static function reviewPaymentsAction(): Action
    {
        return Action::make('reviewPayments')
            ->label(fn (Temple $t): string => $t->payoutAccount?->canReceiveMoney() ? 'Payment verification' : 'Check documents & approve')
            ->icon('heroicon-o-identification')
            ->color('success')
            ->visible(fn (Temple $t): bool => $t->payoutAccount !== null)
            ->url(fn (Temple $t): string => PaymentVerificationResource::getUrl('view', ['record' => $t->payoutAccount]));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTempleBalances::route('/'),
        ];
    }
}
