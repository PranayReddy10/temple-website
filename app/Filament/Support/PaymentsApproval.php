<?php

namespace App\Filament\Support;

use App\Models\Temple;
use App\Models\TemplePayoutAccount;
use Closure;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Model;

/**
 * The admin and portal forms' half of "no money before approval": a paid
 * seva or a paid ticket cannot be switched on for a temple whose owner and
 * bank details staff have not approved. The API and the payment services
 * refuse it as well; this says why at the switch.
 */
class PaymentsApproval
{
    /** The temple the form is about: the record's, the owner record's, or the one picked. */
    public static function temple(Get $get, mixed $livewire, ?Model $record): ?Temple
    {
        if ($record !== null && isset($record->temple_id)) {
            return Temple::query()->find($record->temple_id);
        }

        if ($livewire instanceof RelationManager && $livewire->getOwnerRecord() instanceof Temple) {
            return $livewire->getOwnerRecord();
        }

        return filled($get('temple_id')) ? Temple::query()->find($get('temple_id')) : null;
    }

    /** A validation rule that fails when $isPaid says money is taken and the temple is not approved. */
    public static function rule(Closure $isPaid): Closure
    {
        return fn (Get $get, mixed $livewire, ?Model $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $livewire, $record, $isPaid): void {
            if (! $isPaid($get, $value)) {
                return;
            }

            $temple = self::temple($get, $livewire, $record);

            if ($temple !== null && ! $temple->canCollectPayments()) {
                $fail('This temple cannot take money in the app yet: its bank details and verification documents must be approved first (Finance → Temple balances → Payout & verification).');
            }
        };
    }

    public static function message(): string
    {
        return TemplePayoutAccount::NOT_APPROVED_MESSAGE;
    }
}
