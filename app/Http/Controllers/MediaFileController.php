<?php

namespace App\Http\Controllers;

use App\Support\StorageHealth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\PathTraversalDetected;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Serves locally stored media when the web server could not.
 *
 * In a healthy install this never runs. `public/storage` is a symlink, Apache
 * finds the file on disk and answers before PHP is involved. It runs when
 * that link is missing — which on shared hosting is common enough to plan
 * for: some hosts disable symlink() entirely, some drop the link when the
 * account is moved between servers, and a deploy that unpacks over the top
 * can lose it. The symptom is every uploaded image in the admin panel going
 * blank at once, with nothing in the logs, because the requests never
 * reached the application.
 *
 * Deliberately registered in routes/web.php rather than relying on Laravel's
 * own `serve` option: that one is skipped whenever routes are cached, which
 * is exactly what `app:deploy` does in production. It would therefore work
 * locally and be absent on the server, which is the worst of both.
 *
 * Laravel's ServeFile is not reused either, because it sends
 * `Cache-Control: no-store` — correct for a private download, wrong for a
 * temple photograph that would then be re-read through PHP on every page.
 *
 * Nor is Storage::response(), which streams the whole file and answers a
 * Range request with the entire thing and a 200. That is invisible for a
 * photograph and fatal for a mantra recording: seeking in an audio player
 * re-downloads from the start, and Safari and iOS refuse to play audio or
 * video at all from a server that does not honour ranges. A BinaryFileResponse
 * prepared against the request handles ranges, conditional requests and
 * Accept-Ranges the way a web server would.
 */
class MediaFileController extends Controller
{
    /** A month. These files are replaced by uploading a new one, not edited. */
    protected const CACHE_SECONDS = 2592000;

    public function __invoke(Request $request, string $path): Response
    {
        // Only local disks are served from here. When media lives on Spaces
        // the URLs point at the CDN and this route is not part of the path.
        if (! StorageHealth::isLocal()) {
            throw new NotFoundHttpException;
        }

        $disk = Storage::disk(StorageHealth::mediaDisk());

        try {
            // Flysystem resolves the path and rejects traversal; doing it
            // here rather than on the raw string means the same rules apply
            // as everywhere else the disk is used.
            if (! $disk->exists($path)) {
                throw new NotFoundHttpException;
            }

            $response = new BinaryFileResponse($disk->path($path));

            /*
             * Typed from the extension, the way the web server types it.
             *
             * BinaryFileResponse would otherwise sniff the contents, which
             * disagrees with Apache on the healthy path — an M4A comes back
             * as video/mp4, and on a host without the fileinfo extension
             * everything comes back as application/octet-stream, which a
             * browser downloads instead of playing. The point of this route
             * is to be indistinguishable from the symlink it stands in for.
             */
            $response->headers->set('Content-Type', $disk->mimeType($path) ?: 'application/octet-stream');

            $response->headers->add([
                'Cache-Control' => 'public, max-age='.self::CACHE_SECONDS,
                /*
                 * These are files members of the public uploaded. Serving
                 * them from the application's own origin means a crafted
                 * SVG or HTML file would run as same-origin script, so the
                 * response is sandboxed and told to render nothing the
                 * browser might execute.
                 */
                'Content-Security-Policy' => "default-src 'none'; img-src 'self'; media-src 'self'; style-src 'unsafe-inline'; sandbox",
                'X-Content-Type-Options' => 'nosniff',
            ]);

            // Turns a Range header into a 206 with the slice asked for, and
            // advertises Accept-Ranges so a player knows it may seek.
            return $response->prepare($request);
        } catch (PathTraversalDetected) {
            // ../.. in the path. Flysystem catches it; this makes it a 404
            // rather than a 500, and says nothing about what is up there.
            throw new NotFoundHttpException;
        } catch (NotFoundHttpException $e) {
            throw $e;
        } catch (Throwable) {
            throw new NotFoundHttpException;
        }
    }
}
