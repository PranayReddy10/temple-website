<?php

namespace App\Filament\Temple\Pages;

use App\Enums\TempleStatus;
use App\Filament\Temple\Concerns\PicksTemple;
use App\Support\TempleQr;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * The temple's check-in code, for the gate: devotees scan it with the app
 * (or any camera) to check in and stamp their passport.
 */
class TempleQrCode extends Page
{
    use PicksTemple;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-qr-code';

    protected static string|UnitEnum|null $navigationGroup = 'Temple';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Check-in QR poster';

    protected static ?string $title = 'Check-in QR code';

    protected static ?string $slug = 'qr-code';

    protected string $view = 'filament.temple.pages.qr-code';

    public static function canAccess(): bool
    {
        return Auth::user()?->isTempleAdmin() ?? false;
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $temple = $this->temple();

        return [
            'temple' => $temple,
            'url' => TempleQr::url($temple),
            'svg' => TempleQr::svg($temple),
            'printUrl' => route('temples.qr.print', $temple),
            'downloadUrl' => route('temples.qr.download', $temple),
            'published' => $temple->status === TempleStatus::Published,
        ];
    }
}
