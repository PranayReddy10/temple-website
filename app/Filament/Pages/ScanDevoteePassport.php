<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\ScansDevoteePassports;
use App\Filament\Resources\Devotees\DevoteeResource;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * Scan a devotee's passport code and open their record.
 *
 * Staff see what the code shows and a link through to the account, where
 * visits are verified or revoked. Marking a visit is for the temple's own
 * staff, who are the ones standing next to the devotee.
 */
class ScanDevoteePassport extends Page
{
    use ScansDevoteePassports;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-identification';

    protected static string|\UnitEnum|null $navigationGroup = 'Devotees';

    protected static ?string $title = 'Scan a devotee passport';

    protected static ?string $navigationLabel = 'Scan passport';

    protected static ?string $slug = 'scan-passport';

    protected string $view = 'filament.passport.scan';

    public static function canAccess(): bool
    {
        return Auth::user()?->role?->isStaff() ?? false;
    }

    protected function getViewData(): array
    {
        $devotee = $this->scannedDevotee();

        return [
            'passport' => $this->passportData(),
            'canMark' => false,
            'temples' => [],
            'recordUrl' => $devotee !== null && DevoteeResource::canAccess()
                ? DevoteeResource::getUrl('view', ['record' => $devotee])
                : null,
        ];
    }
}
