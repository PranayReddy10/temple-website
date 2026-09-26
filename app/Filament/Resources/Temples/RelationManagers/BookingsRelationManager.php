<?php

namespace App\Filament\Resources\Temples\RelationManagers;

use App\Filament\Support\PujaBookingTable;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * This temple's bookings, inside its record. Shared by the admin and the
 * temple portal; the portal's record scoping already decides which temple
 * can be open here at all.
 */
class BookingsRelationManager extends RelationManager
{
    protected static string $relationship = 'pujaBookings';

    protected static ?string $title = 'Seva bookings';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-ticket';

    public function table(Table $table): Table
    {
        return PujaBookingTable::configure($table, showTemple: false, staff: Auth::user()?->role?->isStaff() ?? false);
    }

    public static function getBadge(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): ?string
    {
        $today = $ownerRecord->pujaBookings()->live()->forDay(\App\Support\DevotionalClock::now()->toDateString())->count();

        return $today > 0 ? (string) $today : null;
    }
}
