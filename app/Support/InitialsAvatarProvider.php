<?php

namespace App\Support;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * The initials avatar, drawn here instead of fetched from ui-avatars.com.
 *
 * Filament's default provider points the image at a third-party service, so
 * every admin page load sends the signed-in person's name to someone else's
 * server and leaves a broken image behind whenever that server is slow,
 * blocked or simply unreachable — which on a shared host behind a proxy is
 * routine. An inline SVG needs no network at all, and the avatar takes the
 * panel's own saffron-to-kumkum gradient rather than a flat grey.
 */
class InitialsAvatarProvider implements AvatarProvider
{
    public function get(Model|Authenticatable $record): string
    {
        $initials = $this->initials(Filament::getNameForDefaultAvatar($record));
        $colors = config('brand.colors');

        $svg = <<<SVG
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" width="64" height="64" role="img" aria-label="{$initials}">
                <defs>
                    <linearGradient id="g" x1="0" y1="0" x2="1" y2="1">
                        <stop offset="0%" stop-color="{$colors['saffron']['hex']}"/>
                        <stop offset="100%" stop-color="{$colors['kumkum']['hex']}"/>
                    </linearGradient>
                </defs>
                <rect width="64" height="64" fill="url(#g)"/>
                <text x="32" y="33" fill="#ffffff" font-family="system-ui, sans-serif" font-size="26" font-weight="600" text-anchor="middle" dominant-baseline="central">{$initials}</text>
            </svg>
            SVG;

        // Collapsed to one line: a data URI must not carry raw newlines.
        $svg = preg_replace('/\s+/', ' ', trim($svg));

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /** At most two initials, skipping leading punctuation in a name. */
    protected function initials(?string $name): string
    {
        $letters = collect(preg_split('/\s+/', trim((string) $name)) ?: [])
            ->map(fn (string $segment): string => (string) preg_replace('/^[^\p{L}\p{N}]+/u', '', $segment))
            ->filter()
            ->map(fn (string $segment): string => mb_strtoupper(mb_substr($segment, 0, 1)))
            ->take(2)
            ->implode('');

        return $letters === '' ? '?' : e($letters);
    }
}
