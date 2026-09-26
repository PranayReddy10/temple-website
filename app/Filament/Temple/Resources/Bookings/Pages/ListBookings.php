<?php

namespace App\Filament\Temple\Resources\Bookings\Pages;

use App\Filament\Temple\Pages\ScanBooking;
use App\Filament\Temple\Resources\Bookings\BookingResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListBookings extends ListRecords
{
    protected static string $resource = BookingResource::class;

    public function getTitle(): string
    {
        return 'Seva bookings';
    }

    public function getSubheading(): ?string
    {
        return 'Devotees who booked a puja, seva or prasadam in the app, with what they paid and the code to receive them by. Switch booking on per seva under My Temples → Puja & Seva.';
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
