<?php

namespace App\Filament\Resources\PaymentVerifications;

use App\Filament\Resources\PaymentVerifications\Pages\ListPaymentVerifications;
use App\Filament\Resources\PaymentVerifications\Pages\ViewPaymentVerification;
use App\Models\TemplePayoutAccount;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Who asked to take money in a temple's name, with their proof.
 *
 * Temple owners send these from the Trust app: bank details, name and
 * Aadhaar, both sides of the card, a document showing the temple is theirs
 * to represent, and a selfie. Nothing paid opens for the temple until a
 * staff member has looked at them here and approved.
 */
class PaymentVerificationResource extends Resource
{
    protected static ?string $model = TemplePayoutAccount::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-identification';

    protected static string|\UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 0;

    protected static ?string $navigationLabel = 'Payment verifications';

    protected static ?string $modelLabel = 'payment verification';

    protected static ?string $slug = 'payment-verifications';

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['temple:id,name,city', 'verifier:id,name']))
            ->columns([
                TextColumn::make('temple.name')->label('Temple')->searchable()->weight('medium')->wrap()
                    ->description(fn (TemplePayoutAccount $a): ?string => $a->temple?->city),
                TextColumn::make('kyc_name')->label('Person (as on Aadhaar)')->placeholder('Not sent')->searchable(),
                TextColumn::make('aadhaar_last4')->label('Aadhaar')->formatStateUsing(fn (TemplePayoutAccount $a): ?string => $a->maskedAadhaar())->placeholder('—'),
                TextColumn::make('bank')->label('Paid to')->state(fn (TemplePayoutAccount $a): string => $a->isComplete() ? $a->summary() : 'Bank details missing')->wrap(),
                TextColumn::make('documents')->label('Documents')
                    ->state(fn (TemplePayoutAccount $a): string => collect(TemplePayoutAccount::DOCUMENTS)->filter(fn (array $d): bool => filled($a->{$d[0]}))->count().' of 4'),
                TextColumn::make('status')->label('Status')->badge()
                    ->state(fn (TemplePayoutAccount $a): string => self::statusLabel($a->kycStatus()))
                    ->color(fn (TemplePayoutAccount $a): string => match ($a->kycStatus()) {
                        'approved' => 'success', 'pending' => 'warning', 'rejected' => 'danger', default => 'gray',
                    }),
                TextColumn::make('kyc_submitted_at')->label('Sent')->since()->sortable()->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('state')
                    ->label('Status')
                    ->options(['pending' => 'To check', 'approved' => 'Approved', 'rejected' => 'Rejected', 'missing' => 'Documents not sent'])
                    ->default('pending')
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'pending' => $query->whereNotNull('kyc_submitted_at')->whereNull('verified_at')->whereNull('rejection_reason'),
                        'approved' => $query->whereNotNull('verified_at')->whereNotNull('kyc_submitted_at'),
                        'rejected' => $query->whereNull('verified_at')->whereNotNull('rejection_reason'),
                        'missing' => $query->whereNull('kyc_submitted_at'),
                        default => $query,
                    }),
            ])
            ->recordActions([
                ViewAction::make()->label('Check documents'),
                self::approveAction(),
                self::rejectAction(),
            ])
            ->recordUrl(fn (TemplePayoutAccount $a): string => self::getUrl('view', ['record' => $a]))
            ->defaultSort('kyc_submitted_at', 'desc')
            ->emptyStateIcon('heroicon-o-identification')
            ->emptyStateHeading('Nothing to check')
            ->emptyStateDescription('When a temple owner sends their bank details and documents from the Trust app, they appear here.');
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'approved' => 'Approved',
            'pending' => 'To check',
            'rejected' => 'Rejected',
            default => 'Not sent',
        };
    }

    public static function approveAction(): Action
    {
        return Action::make('approve')
            ->label('Approve payments')
            ->icon('heroicon-o-shield-check')
            ->color('success')
            ->visible(fn (TemplePayoutAccount $record): bool => ! $record->canReceiveMoney())
            ->disabled(fn (TemplePayoutAccount $record): bool => ! $record->isComplete() || ! $record->hasKyc())
            ->requiresConfirmation()
            ->modalHeading(fn (TemplePayoutAccount $record): string => 'Approve payments for '.$record->temple?->name.'?')
            ->modalDescription('Only after checking that the photo matches the Aadhaar, the name matches the bank account, and the proof names this temple. Devotees can then pay this temple in the app.')
            ->modalSubmitActionLabel('Approve')
            ->action(function (TemplePayoutAccount $record): void {
                $record->forceFill(['verified_at' => now(), 'verified_by' => Auth::id(), 'rejection_reason' => null])->saveQuietly();
                Notification::make()->title('Approved. The temple can take money in the app.')->success()->send();
            });
    }

    public static function rejectAction(): Action
    {
        return Action::make('reject')
            ->label('Reject')
            ->icon('heroicon-o-no-symbol')
            ->color('danger')
            ->visible(fn (TemplePayoutAccount $record): bool => $record->kyc_submitted_at !== null && $record->kycStatus() !== 'rejected')
            ->modalDescription('Payments stay off (or stop). The owner sees your reason in the Trust app and can send the documents again.')
            ->schema([
                Textarea::make('reason')->label('Reason')->required()->rows(3)
                    ->placeholder('e.g. The Aadhaar photo is not readable; the proof does not name this temple; the selfie does not match the Aadhaar.'),
            ])
            ->action(function (TemplePayoutAccount $record, array $data): void {
                $record->forceFill(['verified_at' => null, 'verified_by' => null, 'rejection_reason' => $data['reason']])->saveQuietly();
                Notification::make()->title('Rejected. The owner will see the reason.')->success()->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPaymentVerifications::route('/'),
            'view' => ViewPaymentVerification::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /** Waiting to be checked is the reason to open this. */
    public static function getNavigationBadge(): ?string
    {
        $n = TemplePayoutAccount::query()->whereNotNull('kyc_submitted_at')->whereNull('verified_at')->whereNull('rejection_reason')->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /** Identity documents: super admins only, as for temple access. */
    public static function canAccess(): bool
    {
        return Auth::user()?->canManageUsers() ?? false;
    }
}
