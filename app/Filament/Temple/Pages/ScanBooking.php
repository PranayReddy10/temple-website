<?php

namespace App\Filament\Temple\Pages;

use App\Filament\Concerns\ScansBookings;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * The seva counter: scan the code on a devotee's booking, see what they
 * booked and paid, and mark them received. Once.
 */
class ScanBooking extends Page
{
    use ScansBookings;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-qr-code';

    protected static ?string $title = 'Scan a seva booking';

    protected static ?string $navigationLabel = 'Scan booking';

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'scan-booking';

    protected string $view = 'filament.bookings.scan';

    public static function canAccess(): bool
    {
        return Auth::user()?->isTempleAdmin() ?? false;
    }
}
