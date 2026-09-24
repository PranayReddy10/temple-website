<?php

namespace App\Filament\Support;

use App\Models\Temple;
use App\Support\TempleQr;
use Closure;
use Filament\Actions\Action;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "Check-in QR code" and "Download QR" for one temple.
 *
 * Shared by the admin panel and the temple portal, so a temple team prints
 * the same signed code an editor would. Only the temple is needed; who may
 * reach it is decided by the page the actions sit on.
 */
final class TempleQrActions
{
    /**
     * @param  Closure(): Temple  $temple
     * @return array<Action>
     */
    public static function make(Closure $temple): array
    {
        return [
            Action::make('checkinQr')
                ->label('Check-in QR code')
                ->icon('heroicon-o-qr-code')
                ->color('gray')
                ->modalHeading('Check-in QR code')
                ->modalContent(fn (): View => view('filament.temples.qr', ['temple' => $temple()]))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close'),
            Action::make('downloadCheckinQr')
                ->label('Download QR')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn (): StreamedResponse => response()->streamDownload(
                    fn () => print (TempleQr::svg($temple())),
                    $temple()->slug.'-checkin-qr.svg',
                    ['Content-Type' => 'image/svg+xml'],
                )),
        ];
    }
}
