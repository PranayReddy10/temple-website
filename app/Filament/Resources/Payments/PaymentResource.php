<?php

namespace App\Filament\Resources\Payments;

use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Models\Payment;
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
use Throwable;

/**
 * Every payment attempt, read-only apart from asking the gateway again and
 * recording a refund made in the gateway's own dashboard.
 */
class PaymentResource extends Resource
{
    protected static ?string $model = Payment::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static string|\UnitEnum|null $navigationGroup = 'Monetisation';

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'monetisation/payments-list';

    protected static ?string $navigationLabel = 'Payments';

    public static function canAccess(): bool
    {
        return Auth::user()?->canManageUsers() ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['devotee:id,name,email,phone', 'plan:id,name']))
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime('d M Y, H:i')->sortable(),
                TextColumn::make('devotee.name')->label('Devotee')->searchable()->description(fn (Payment $r) => $r->devotee?->email ?? $r->devotee?->phone),
                TextColumn::make('plan.name')->label('Plan')->placeholder('—'),
                TextColumn::make('amount_paise')->label('Amount')->formatStateUsing(fn (Payment $r) => $r->amountLabel())->alignEnd()->sortable(),
                TextColumn::make('gateway')->formatStateUsing(fn (string $state) => Payment::GATEWAYS[$state] ?? $state)->badge()->color('gray'),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'paid' => 'success', 'failed' => 'danger', 'refunded' => 'warning', default => 'gray',
                }),
                TextColumn::make('gateway_payment_id')->label('Gateway ref')->copyable()->placeholder('—')->toggleable(),
                TextColumn::make('failure_reason')->label('Reason')->limit(40)->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(['created' => 'Created', 'pending' => 'Pending', 'paid' => 'Paid', 'failed' => 'Failed', 'refunded' => 'Refunded']),
                SelectFilter::make('gateway')->options(Payment::GATEWAYS),
            ])
            ->recordActions([
                Action::make('reconcile')->label('Check with gateway')->icon('heroicon-o-arrow-path')->color('gray')
                    ->visible(fn (Payment $r) => ! $r->isSettled() && $r->gateway_order_id !== null)
                    ->action(function (Payment $r): void {
                        try {
                            $after = app(Payments::class)->reconcile($r);
                            Notification::make()->title('Status: '.$after->status)->success()->send();
                        } catch (Throwable $e) {
                            Notification::make()->title('The gateway did not answer')->body($e->getMessage())->danger()->send();
                        }
                    }),
                Action::make('refunded')->label('Mark refunded')->icon('heroicon-o-receipt-refund')->color('warning')
                    ->visible(fn (Payment $r) => $r->isPaid())
                    ->requiresConfirmation()
                    ->modalDescription('Refund the money in the gateway\'s own dashboard first. This records it here and ends the plan it bought.')
                    ->action(fn (Payment $r) => app(Payments::class)->refunded($r)),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No payments yet');
    }

    public static function getPages(): array
    {
        return ['index' => ListPayments::route('/')];
    }
}
