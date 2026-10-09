<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Addresses for the public, indexable pages.
 *
 * The devotees' website (darshansaathi.com) is rendered entirely by this
 * application: darshansaathi.com's .htaccess (deploy/website.htaccess) hands
 * every address to it. The same pages also answer on
 * temple.darshansaathi.com; there they point search engines at the
 * website's copy and ask not to be indexed, so nothing is listed twice.
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

    /**
     * Where "Open in the app", "Book a seva" and "Donate" go from a website
     * page: the app's store page, or the home page's Get the app section
     * when no store link is set. On Android the page swaps in appIntent(),
     * which opens the installed app on this temple instead.
     */
    public static function appLink(string $slug, ?string $action = null): string
    {
        return self::storeUrl() ?? self::url('/').'#app';
    }

    /** The installed Android app opened on a temple (and its sevas or hundi with an action). */
    public static function appIntent(string $slug, ?string $action = null): string
    {
        return AppLinks::androidIntent(
            self::url('temples/'.$slug).($action !== null ? '?'.http_build_query(['action' => $action]) : ''),
            self::appLink($slug, $action),
        );
    }

    /** The app's store page, from Administration → App control, if set. */
    public static function storeUrl(): ?string
    {
        $url = setting('app_android_store_url');

        return filled($url) ? (string) $url : null;
    }

    /**
     * A verification code as pasted: just the code, or the whole
     * <meta name="google-site-verification" content="…"> tag Search Console
     * gives, from which the code is taken.
     */
    public static function verificationCode(?string $pasted): ?string
    {
        $pasted = trim((string) $pasted);
        if ($pasted === '') {
            return null;
        }
        if (preg_match('/content\s*=\s*["\']([^"\']+)["\']/i', $pasted, $m) === 1) {
            return trim($m[1]);
        }

        return preg_match('/^[A-Za-z0-9_\-]+$/', $pasted) === 1 ? $pasted : null;
    }

    /**
     * Everything that goes at the end of every website page's <head>: the
     * search engines' verification tags and whatever was pasted under
     * Analytics & SEO → Code for every page.
     */
    public static function headExtras(): string
    {
        $out = [];
        if ($google = self::verificationCode(setting('google_site_verification'))) {
            $out[] = '<meta name="google-site-verification" content="'.e($google).'">';
        }
        if ($bing = self::verificationCode(setting('bing_site_verification'))) {
            $out[] = '<meta name="msvalidate.01" content="'.e($bing).'">';
        }
        if (filled($head = setting('custom_head_html'))) {
            $out[] = (string) $head;
        }

        return implode("\n", $out);
    }

    /** Pasted code for the start of every website page's <body> (a tag manager's noscript, a chat widget). */
    public static function bodyExtras(): string
    {
        return (string) setting('custom_body_html');
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
