<?php

namespace App\Filament\Resources\TempleSettlements;

use App\Filament\Resources\TempleSettlements\Pages\ListTempleSettlements;
use App\Filament\Support\SettlementTable;
use App\Models\TempleSettlement;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * Every payout to a temple: prepared, paid with its bank reference, or
 * cancelled back into the balance.
 */
class TempleSettlementResource extends Resource
{
    protected static ?string $model = TempleSettlement::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static string|\UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Settlements';

    protected static ?string $modelLabel = 'settlement';

    protected static ?string $slug = 'finance/settlements';

    protected static ?string $recordTitleAttribute = 'reference';

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
        return SettlementTable::configure($table, staff: true);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTempleSettlements::route('/'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = TempleSettlement::query()->pending()->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Waiting for the transfer';
    }
}
