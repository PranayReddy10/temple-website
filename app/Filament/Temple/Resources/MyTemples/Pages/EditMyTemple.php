<?php

namespace App\Filament\Temple\Resources\MyTemples\Pages;

use App\Filament\Temple\Pages\ScanBooking;
use App\Filament\Temple\Pages\ScanPassport;
use App\Filament\Temple\Resources\MyTemples\MyTempleResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\View\View;

class EditMyTemple extends EditRecord
{
    protected static string $resource = MyTempleResource::class;

    /**
     * No delete action: a temple team cannot remove its own listing.
     *
     * The check-in code is here so the team can print it for their own gate
     * without asking the editors for it.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('checkinQr')
                ->label('Check-in QR code')
                ->icon('heroicon-o-qr-code')
                ->color('gray')
                ->modalHeading('Check-in QR code')
                ->modalContent(fn (): View => view('filament.temples.qr', ['temple' => $this->getRecord()]))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close'),
            Action::make('printCheckinQr')
                ->label('Print QR')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->url(fn (): string => route('temples.qr.print', $this->getRecord()))
                ->openUrlInNewTab(),
            Action::make('scanPassport')
                ->label('Scan a passport')
                ->icon('heroicon-o-identification')
                ->color('gray')
                ->url(ScanPassport::getUrl()),
            Action::make('scanBooking')
                ->label('Scan a booking')
                ->icon('heroicon-o-ticket')
                ->url(ScanBooking::getUrl()),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
