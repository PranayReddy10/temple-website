<?php

namespace App\Filament\Resources\Temples\Pages;

use App\Filament\Concerns\SyncsCoverPhoto;
use App\Filament\Resources\Temples\TempleResource;
use App\Support\TempleQr;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EditTemple extends EditRecord
{
    use SyncsCoverPhoto;

    protected static string $resource = TempleResource::class;

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
            Action::make('downloadCheckinQr')
                ->label('Download QR')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn (): StreamedResponse => response()->streamDownload(
                    fn () => print (TempleQr::svg($this->getRecord())),
                    $this->getRecord()->slug.'-checkin-qr.svg',
                    ['Content-Type' => 'image/svg+xml'],
                )),
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    /** @param array<string, mixed> $data */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $this->fillCoverPhoto($data, $this->getRecord());
    }

    /** @param array<string, mixed> $data */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->extractCoverPhoto($data);
    }

    protected function afterSave(): void
    {
        $this->syncCoverPhoto($this->getRecord());
    }
}
