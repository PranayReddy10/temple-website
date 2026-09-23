<?php

namespace App\Http\Controllers;

use App\Support\Pwa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The three files a browser needs before it will install the panel.
 *
 * Served by the application rather than written into public/, because two of
 * the three have to know something only the application knows: the manifest
 * carries the product name, which is a setting, and the worker carries the
 * asset version, which changes with every deploy. A static file would go stale
 * in exactly the way that leaves an installed app showing last week's
 * stylesheet.
 */
class PwaController extends Controller
{
    /** A day. Long enough to be worth caching, short enough to recover from. */
    protected const MANIFEST_SECONDS = 86400;

    public function manifest(string $panel): JsonResponse
    {
        if (! Pwa::isInstallable($panel)) {
            throw new NotFoundHttpException;
        }

        return response()
            ->json(Pwa::manifest($panel), options: JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
            ->withHeaders([
                'Content-Type' => 'application/manifest+json',
                'Cache-Control' => 'public, max-age='.self::MANIFEST_SECONDS,
            ]);
    }

    /**
     * The service worker.
     *
     * Served from the site root on purpose: a worker may only control pages
     * at or below its own path, so one at /pwa/sw.js could never control
     * /admin. Hence the route below it at '/sw.js'.
     *
     * Never cached by the browser. This is the file that says what to do with
     * every other file, so a stale copy is the one failure that cannot fix
     * itself — the browser would keep asking an old worker whether there is a
     * new worker.
     */
    public function serviceWorker(): Response
    {
        return response()
            ->view('pwa.service-worker', ['version' => Pwa::assetVersion()])
            ->withHeaders([
                'Content-Type' => 'application/javascript',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                // Belt and braces: allows a root scope even if the file is
                // ever moved somewhere below it.
                'Service-Worker-Allowed' => '/',
            ]);
    }

    /**
     * What an installed panel shows with no connection.
     *
     * Not an apology page. The useful thing it can say is which panel this is
     * and that nothing was lost, and then get out of the way with a button
     * that retries — because the usual cause is a tunnel, and the usual fix is
     * to try again in a minute.
     */
    public function offline(Request $request): Response
    {
        return response()
            ->view('pwa.offline')
            ->withHeaders(['Cache-Control' => 'no-cache']);
    }
}
