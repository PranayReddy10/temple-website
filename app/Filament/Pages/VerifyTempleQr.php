<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Temples\TempleResource;
use App\Support\TempleQr;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * Point the camera at any code and learn whether we issued it.
 *
 * For the day a temple office sends a photo of a code that "does not work",
 * or someone reports a sticker that looks like ours: scan it here, or paste
 * what it says, and the answer names the temple it belongs to.
 */
class VerifyTempleQr extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-qr-code';

    protected static string|\UnitEnum|null $navigationGroup = 'Temples';

    protected static ?string $title = 'Verify a temple QR code';

    protected static ?string $navigationLabel = 'Verify QR code';

    protected static ?string $slug = 'verify-qr';

    protected string $view = 'filament.pages.verify-temple-qr';

    public string $code = '';

    /** @var array{valid: bool, reason: string, temple: ?string, url: ?string}|null */
    public ?array $result = null;

    public static function canAccess(): bool
    {
        return Auth::user()?->role?->isStaff() ?? false;
    }

    public function verify(?string $scanned = null): void
    {
        if ($scanned !== null) {
            $this->code = $scanned;
        }

        $check = TempleQr::verify($this->code);
        $temple = $check['temple'];

        $this->result = [
            'valid' => $check['valid'],
            'reason' => trim($this->code) === '' ? 'Scan a code or paste what it says.' : $check['reason'],
            'temple' => $temple?->name,
            'url' => $temple !== null && TempleResource::canAccess() ? TempleResource::getUrl('edit', ['record' => $temple]) : null,
        ];
    }
}
