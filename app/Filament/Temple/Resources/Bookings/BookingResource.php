<?php

namespace App\Filament\Temple\Resources\Bookings;

use App\Filament\Support\PujaBookingTable;
use App\Filament\Temple\Resources\Bookings\Pages\ListBookings;
use App\Models\PujaBooking;
use App\Support\DevotionalClock;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * The bookings a temple's own team receives.
 *
 * The same security boundary as the rest of the portal: every query runs
 * through the signed-in user's approved temples, so a booking at any other
 * temple is not merely hidden but absent.
 */
class BookingResource extends Resource
{
    protected static ?string $model = PujaBooking::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-ticket';

    protected static ?string $navigationLabel = 'Seva bookings';

    protected static ?string $modelLabel = 'booking';

    protected static ?string $pluralModelLabel = 'bookings';

    protected static ?string $slug = 'bookings';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'reference';

    public static function table(Table $table): Table
    {
        return PujaBookingTable::configure($table, showTemple: true, staff: false);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('temple_id', static::visibleTempleIds());
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()->whereIn('temple_id', static::visibleTempleIds());
    }

    /** @return array<int, int> */
    protected static function visibleTempleIds(): array
    {
        return Auth::user()?->approvedTempleIds() ?? [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBookings::route('/'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        $ids = static::visibleTempleIds();

        if ($ids === []) {
            return null;
        }

        $today = PujaBooking::query()->whereIn('temple_id', $ids)->live()->forDay(DevotionalClock::now()->toDateString())->count();

        return $today > 0 ? (string) $today : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Bookings for today';
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->isTempleAdmin() ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }
}
