<?php

namespace App\Filament\Temple\Resources\Settlements;

use App\Filament\Support\SettlementTable;
use App\Filament\Temple\Resources\Settlements\Pages\ListSettlements;
use App\Models\TempleSettlement;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Payouts to the temple for its paid seva bookings, read-only: what devotees
 * paid, the platform fee, what the temple received and the bank reference.
 * Scoped to the signed-in user's approved temples, like the rest of the portal.
 */
class SettlementResource extends Resource
{
    protected static ?string $model = TempleSettlement::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static string|\UnitEnum|null $navigationGroup = 'Money';

    protected static ?string $navigationLabel = 'Settlements';

    protected static ?string $modelLabel = 'settlement';

    protected static ?string $slug = 'settlements';

    protected static ?int $navigationSort = 3;

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
        return SettlementTable::configure($table, staff: false);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereIn('temple_id', Auth::user()?->approvedTempleIds() ?? [])
            ->where('status', '!=', TempleSettlement::CANCELLED);
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return static::getEloquentQuery();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSettlements::route('/'),
        ];
    }
}
