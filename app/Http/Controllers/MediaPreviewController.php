<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * An uploaded file, read back for the admin panel's upload fields.
 *
 * Filament's upload field shows a file that is already saved by downloading
 * it with fetch(), not by an <img>. When media lives on DigitalOcean Spaces
 * that is a request to another domain, which the browser refuses unless the
 * Space sends CORS headers, and the field then sits at "Waiting for size"
 * for ever, while the gallery (plain <img> tags, which need no CORS) shows
 * the same photo perfectly well. Serving the preview from this host makes it
 * a same-origin request that needs nothing configured on the Space.
 *
 * Only reachable through a signed URL the form itself generated, so it
 * cannot be used to read arbitrary paths off a disk.
 */
class MediaPreviewController extends Controller
{
    /** Disks an upload field may point at. */
    public const DISKS = ['public', 'spaces'];

    public function __invoke(Request $request): Response
    {
        $disk = (string) $request->query('disk');
        $path = (string) $request->query('path');

        if (! in_array($disk, self::DISKS, true) || $path === '') {
            throw new NotFoundHttpException;
        }

        try {
            $storage = Storage::disk($disk);

            if (! $storage->exists($path)) {
                throw new NotFoundHttpException;
            }

            return $storage->response($path, headers: ['Cache-Control' => 'private, max-age=3600']);
        } catch (NotFoundHttpException $e) {
            throw $e;
        } catch (Throwable) {
            throw new NotFoundHttpException;
        }
    }
}
