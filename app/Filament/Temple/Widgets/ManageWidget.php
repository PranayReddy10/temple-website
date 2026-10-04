<?php

namespace App\Filament\Temple\Widgets;

use App\Filament\Temple\Pages\BankDetails;
use App\Filament\Temple\Pages\Finance;
use App\Filament\Temple\Pages\ScanBooking;
use App\Filament\Temple\Pages\ScanPassport;
use App\Filament\Temple\Pages\TempleQrCode;
use App\Filament\Temple\Resources\Bookings\BookingResource;
use App\Filament\Temple\Resources\EventTickets\EventTicketResource;
use App\Filament\Temple\Resources\HundiDonations\HundiDonationResource;
use App\Filament\Temple\Resources\MyTemples\MyTempleResource;
use App\Filament\Temple\Resources\Settlements\SettlementResource;
use App\Filament\Temple\Resources\SupportTickets\SupportTicketResource;
use App\Filament\Temple\Widgets\Concerns\ForCurrentTemple;
use Filament\Widgets\Widget;

/** Everything the trust app can do, one click away. */
class ManageWidget extends Widget
{
    use ForCurrentTemple;

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = ['md' => 2, 'xl' => 1];

    protected string $view = 'filament.temple.widgets.manage';

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $temple = $this->temple();
        if ($temple === null) {
            return ['groups' => []];
        }
        $tab = fn (int $i): string => MyTempleResource::getUrl('edit', ['record' => $temple, 'relation' => (string) $i]);

        return ['groups' => [
            'Temple' => [
                ['Details', 'heroicon-o-pencil-square', MyTempleResource::getUrl('edit', ['record' => $temple])],
                ['Timings', 'heroicon-o-clock', $tab(1)],
                ['Closures', 'heroicon-o-no-symbol', $tab(6)],
                ['Sevas', 'heroicon-o-sparkles', $tab(2)],
                ['Events', 'heroicon-o-calendar-days', $tab(5)],
                ['Photos', 'heroicon-o-photo', $tab(0)],
                ['Reviews', 'heroicon-o-chat-bubble-left-right', $tab(4)],
                ['QR poster', 'heroicon-o-qr-code', TempleQrCode::getUrl()],
            ],
            'Counter' => [
                ['Scan booking', 'heroicon-o-viewfinder-circle', ScanBooking::getUrl()],
                ['Stamp passport', 'heroicon-o-identification', ScanPassport::getUrl()],
                ['Seva bookings', 'heroicon-o-ticket', BookingResource::getUrl()],
                ['Event tickets', 'heroicon-o-musical-note', EventTicketResource::getUrl()],
            ],
            'Money' => [
                ['Finance', 'heroicon-o-chart-bar', Finance::getUrl()],
                ['Online hundi', 'heroicon-o-gift', HundiDonationResource::getUrl()],
                ['Settlements', 'heroicon-o-banknotes', SettlementResource::getUrl()],
                ['Bank & verification', 'heroicon-o-building-library', BankDetails::getUrl()],
            ],
            'Help' => [
                ['Support', 'heroicon-o-lifebuoy', SupportTicketResource::getUrl()],
            ],
        ]];
    }
}
