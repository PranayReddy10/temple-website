<?php

namespace App\Filament\Resources\PujaBookings\Pages;

use App\Filament\Pages\ScanBooking;
use App\Filament\Resources\PujaBookings\PujaBookingResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListPujaBookings extends ListRecords
{
    protected static string $resource = PujaBookingResource::class;

    public function getHeading(): string
    {
        return 'Seva bookings';
    }

    public function getSubheading(): ?string
    {
        return 'Every temple\'s bookings made in the app. Temples receive their own from their portal; refunds are recorded here.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('scan')
                ->label('Scan a booking code')
                ->icon('heroicon-o-qr-code')
                ->url(ScanBooking::getUrl()),
        ];
    }
}
