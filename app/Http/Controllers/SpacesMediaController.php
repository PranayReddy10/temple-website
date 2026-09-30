<?php

namespace App\Http\Controllers;

use App\Support\MediaStorage;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * A photo on the Space, served from this host for the website.
 *
 * The Flutter website reads images by script, and a browser only allows that
 * across domains when the other side sends Access-Control-Allow-Origin. The
 * Space's CDN does not dependably do so (a rule set on the Space reaches the
 * CDN late, and cached copies keep the old headers), so photos there showed
 * on the phones and the /temples pages but not on darshansaathi.com. The API
 * gives browsers this address instead (MediaUrl::forBrowser); phones keep
 * the CDN. Cached for a month by the browser, since uploads get new names
 * rather than being overwritten.
 */
class SpacesMediaController extends Controller
{
    protected const TYPES = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp',
        'gif' => 'image/gif', 'avif' => 'image/avif', 'svg' => 'image/svg+xml',
    ];

    public function __invoke(string $path): Response
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        // Images only, and never a path that climbs out of the bucket.
        if (! isset(self::TYPES[$extension]) || str_contains($path, '..') || ! MediaStorage::spacesIsFilledIn()) {
            throw new NotFoundHttpException;
        }

        try {
            $disk = Storage::disk(MediaStorage::SPACES_DISK);
            $stream = $disk->readStream($path);
        } catch (Throwable) {
            $stream = null;
        }

        if (! is_resource($stream)) {
            throw new NotFoundHttpException;
        }

        return new StreamedResponse(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => self::TYPES[$extension],
            'Cache-Control' => 'public, max-age=2592000, immutable',
            'Access-Control-Allow-Origin' => '*',
            'Cross-Origin-Resource-Policy' => 'cross-origin',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
