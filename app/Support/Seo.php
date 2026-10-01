<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Addresses for the public, indexable pages.
 *
 * The devotees' website (darshansaathi.com) is a Flutter build, which search
 * engines read as an empty page. The temple and state pages are therefore
 * rendered here, and darshansaathi.com's .htaccess hands /temples, /states,
 * /sitemap*.xml and /storage to this application. The same pages also answer
 * on temple.darshansaathi.com; there they point search engines at the
 * website's copy and ask not to be indexed, so nothing is listed twice.
 * /deities and Search Console's google*.html check are routed here too.
 */
final class Seo
{
    public static function website(): string
    {
        return rtrim((string) config('brand.website'), '/');
    }

    /** The website's address for a path on it. */
    public static function url(string $path = '/'): string
    {
        return self::website().'/'.ltrim($path, '/');
    }

    /** A root-relative media URL made absolute on the website. */
    public static function absolute(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        return str_starts_with($url, '/') && ! str_starts_with($url, '//') ? self::url($url) : $url;
    }

    /** Whether this request came in on the website rather than the admin host. */
    public static function onWebsite(Request $request): bool
    {
        return strcasecmp($request->getHost(), (string) parse_url(self::website(), PHP_URL_HOST)) === 0;
    }

    /** The website's Google Analytics stream, when analytics is on. */
    public static function measurementId(): ?string
    {
        $id = setting('firebase_measurement_id');

        return (bool) setting('analytics_enabled', null, false) && is_string($id) && preg_match('/^G-[A-Z0-9]+$/', $id) ? $id : null;
    }
}
