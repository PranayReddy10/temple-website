<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Media URLs as a client outside this host must see them.
 *
 * The local `public` disk is configured with the relative `/storage` prefix so
 * the admin panel works on any hostname. A phone has no idea which host that
 * is relative to, so the API resolves it against the request host here, in
 * one place, before it leaves the server. A Spaces URL is already absolute
 * and passes through untouched.
 */
final class MediaUrl
{
    public static function for(?string $disk, ?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        $disk ??= config('filesystems.media');

        // A browser (the website) reads photos on another domain only when
        // that domain sends CORS headers, and a Space behind its CDN does
        // not reliably do so. Browsers calling the API therefore get Spaces
        // photos through this host (SpacesMediaController), which does.
        // Phones send no Origin and keep the CDN address.
        if ($disk === MediaStorage::SPACES_DISK && self::forBrowser()) {
            return url('/media/'.ltrim($path, '/'));
        }

        return self::absolute(Storage::disk($disk)->url($path));
    }

    /** An API request made by a web page on another domain. */
    public static function forBrowser(): bool
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return false;
        }

        $request = request();

        return $request->is('api/*') && filled($request->headers->get('Origin'));
    }

    public static function absolute(?string $url): ?string
    {
        if (blank($url)) {
            return null;
        }

        return Str::startsWith($url, ['http://', 'https://', '//']) ? $url : url($url);
    }
}
