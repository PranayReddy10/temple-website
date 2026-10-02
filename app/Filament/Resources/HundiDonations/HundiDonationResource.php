<?php

namespace App\Filament\Resources\HundiDonations;

use App\Filament\Resources\HundiDonations\Pages\ListHundiDonations;
use App\Models\TempleDonation;
use App\Support\DevotionalClock;
use App\Support\Payments\Payments;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Online hundi gifts to every temple. Staff see the giver even when the
 * temple sees "A devotee", for refunds and the gateway's records.
 */
class HundiDonationResource extends Resource
{
    protected static ?string $model = TempleDonation::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-gift';

    protected static string|\UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Hundi donations';

    protected static ?string $modelLabel = 'hundi donation';

    protected static ?string $slug = 'finance/hundi';

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

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['temple:id,name,city', 'devotee:id,name,email,phone', 'payment:id,status,gateway,gateway_payment_id']))
            ->columns([
                TextColumn::make('paid_at')->label('When')->dateTime('d M Y, H:i', DevotionalClock::timezone())->placeholder('Not paid')->sortable(),
                TextColumn::make('temple.name')->label('Temple')->weight('medium')->searchable(),
                TextColumn::make('donor_name')->label('Given by')->searchable()
                    ->description(fn (TempleDonation $d): string => ($d->is_anonymous ? 'Anonymous to the temple · ' : '').($d->devotee?->email ?? $d->devotee?->phone ?? '')),
                TextColumn::make('purpose')->formatStateUsing(fn (TempleDonation $d): string => $d->purposeLabel())->badge()->color('gray'),
                TextColumn::make('amount_paise')->label('Amount')->state(fn (TempleDonation $d): string => $d->amountLabel())->alignEnd()->sortable(),
                TextColumn::make('status')->badge()->formatStateUsing(fn (TempleDonation $d): string => $d->statusLabel())
                    ->color(fn (string $state): string => match ($state) {
                        TempleDonation::PAID => 'success', TempleDonation::REFUNDED => 'danger', TempleDonation::FAILED => 'gray', default => 'warning',
                    }),
                TextColumn::make('reference')->fontFamily('mono')->copyable()->searchable()->toggleable(),
                TextColumn::make('settlement_id')->label('Settled')->state(fn (TempleDonation $d): string => $d->settlement_id ? 'Yes' : '—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    TempleDonation::PAID => 'Received', TempleDonation::PENDING => 'Awaiting payment',
                    TempleDonation::FAILED => 'Not completed', TempleDonation::REFUNDED => 'Refunded',
                ])->default(TempleDonation::PAID),
                SelectFilter::make('purpose')->options(TempleDonation::PURPOSES),
                SelectFilter::make('temple')->relationship('temple', 'name')->searchable()->preload(),
            ])
            ->recordActions([
                Action::make('refunded')
                    ->label('Mark refunded')
                    ->icon('heroicon-o-receipt-refund')
                    ->color('warning')
                    ->visible(fn (TempleDonation $d): bool => $d->isPaid() && $d->payment !== null)
                    ->requiresConfirmation()
                    ->modalDescription(fn (TempleDonation $d): string => 'Only after refunding it in the payment gateway.'
                        .($d->settlement_id ? ' It was already settled with the temple: recover it from the temple\'s next payout.' : ''))
                    ->action(function (TempleDonation $d): void {
                        app(Payments::class)->refunded($d->payment);
                        Notification::make()->title('Marked refunded.')->success()->send();
                    }),
            ])
            ->defaultSort('id', 'desc')
            ->emptyStateIcon('heroicon-o-gift')
            ->emptyStateHeading('No hundi donations yet')
            ->emptyStateDescription('A temple\'s owner switches the online hundi on in the Darshan Saathi Trust app.');
    }

    public static function getPages(): array
    {
        return ['index' => ListHundiDonations::route('/')];
    }
}
