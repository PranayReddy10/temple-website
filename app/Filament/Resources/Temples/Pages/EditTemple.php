<?php

namespace App\Filament\Resources\Temples\Pages;

use App\Enums\TempleStatus;
use App\Filament\Concerns\SyncsCoverPhoto;
use App\Filament\Resources\Temples\OfficialSiteActions;
use App\Filament\Resources\Temples\TempleResource;
use App\Support\SiteLocale;
use App\Support\TempleQr;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\HtmlString;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EditTemple extends EditRecord
{
    use SyncsCoverPhoto;

    protected static string $resource = TempleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // The public page as devotees and search engines see it; a
            // temple not yet published opens a private preview instead.
            Action::make('viewPage')
                ->label(fn (): string => $this->getRecord()->status === TempleStatus::Published ? 'View page' : 'Preview page')
                ->icon('heroicon-o-eye')
                ->color('gray')
                ->url(fn (): string => $this->getRecord()->status === TempleStatus::Published
                    ? SiteLocale::templeUrl($this->getRecord(), null)
                    : URL::temporarySignedRoute('site.temple.preview', now()->addHour(), ['temple' => $this->getRecord()->getKey()]))
                ->openUrlInNewTab(),
            OfficialSiteActions::read(),
            OfficialSiteActions::review(),
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
            // Permanent removal is for super admins only.
            ForceDeleteAction::make()->visible(fn (): bool => Auth::user()?->canManageUsers() ?? false),
            RestoreAction::make(),
        ];
    }

    /**
     * How complete the public page is, under the title: the score and what
     * to add, so the next thing to fill in is in view while editing.
     */
    public function getSubheading(): string|Htmlable|null
    {
        ['score' => $score, 'missing' => $missing] = $this->getRecord()->pageChecklist();
        $color = $score >= 80 ? '#15803d' : ($score >= 50 ? '#b45309' : '#b91c1c');

        return new HtmlString(
            '<span style="display:inline-block;padding:2px 10px;border-radius:999px;font-weight:600;color:#fff;background:'.$color.'">Page score '.$score.'%</span> '
            .e($missing === [] ? 'Everything a temple page needs is filled in.' : 'Add: '.implode(', ', $missing).'.')
        );
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
