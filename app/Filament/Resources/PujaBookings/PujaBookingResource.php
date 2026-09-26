<?php

namespace App\Filament\Resources\PujaBookings;

use App\Filament\Resources\PujaBookings\Pages\ListPujaBookings;
use App\Filament\Support\PujaBookingTable;
use App\Models\PujaBooking;
use App\Support\DevotionalClock;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * Every temple's seva bookings in one list.
 *
 * Temples receive their own bookings in their portal; this is where staff
 * see all of them, answer a devotee who says a payment went through and
 * the booking did not, and record a refund.
 */
class PujaBookingResource extends Resource
{
    protected static ?string $model = PujaBooking::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-ticket';

    protected static string|\UnitEnum|null $navigationGroup = 'Temples';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Seva bookings';

    protected static ?string $modelLabel = 'seva booking';

    protected static ?string $pluralModelLabel = 'seva bookings';

    protected static ?string $slug = 'seva-bookings';

    protected static ?string $recordTitleAttribute = 'reference';

    public static function table(Table $table): Table
    {
        return PujaBookingTable::configure($table, showTemple: true, staff: true);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPujaBookings::route('/'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        $today = PujaBooking::query()->live()->forDay(DevotionalClock::now()->toDateString())->count();

        return $today > 0 ? (string) $today : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Bookings for today';
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->role?->isStaff() ?? false;
    }

    /** Bookings are made by devotees in the app, never typed in here. */
    public static function canCreate(): bool
    {
        return false;
    }
}
