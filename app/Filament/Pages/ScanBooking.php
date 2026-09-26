<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\ScansBookings;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * The same counter for staff, across every temple: for the devotee who
 * writes in with a screenshot, and for a temple whose own portal is down.
 */
class ScanBooking extends Page
{
    use ScansBookings;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-qr-code';

    protected static string|\UnitEnum|null $navigationGroup = 'Temples';

    protected static ?int $navigationSort = 5;

    protected static ?string $title = 'Scan a seva booking';

    protected static ?string $navigationLabel = 'Scan booking code';

    protected static ?string $slug = 'scan-booking';

    protected string $view = 'filament.bookings.scan';

    public static function canAccess(): bool
    {
        return Auth::user()?->role?->isStaff() ?? false;
    }
}
