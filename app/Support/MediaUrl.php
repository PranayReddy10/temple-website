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

        return self::absolute(Storage::disk($disk ?? config('filesystems.media'))->url($path));
    }

    public static function absolute(?string $url): ?string
    {
        if (blank($url)) {
            return null;
        }

        return Str::startsWith($url, ['http://', 'https://', '//']) ? $url : url($url);
    }
}
