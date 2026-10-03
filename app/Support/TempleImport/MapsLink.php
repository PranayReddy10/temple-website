<?php

namespace App\Support\TempleImport;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * What a Google Maps link itself says: the place's name and its pin.
 *
 * Only the link is read. A short link (maps.app.goo.gl) is followed to the
 * full address it stands for, and the name and coordinates are taken out of
 * that address; Google's page is never fetched or scraped, which its terms
 * do not allow. Phone, hours and photos come from the temple's own website
 * and from freely licensed sources instead.
 */
final class MapsLink
{
    private const HOSTS = '/(^|\.)(google\.[a-z.]+|goo\.gl|g\.co|maps\.app\.goo\.gl)$/i';

    /** @return array{url: string, name: ?string, latitude: ?float, longitude: ?float, cid: ?string}|array{error: string} */
    public static function read(string $link): array
    {
        $link = trim($link);
        if (! preg_match('#^https?://#i', $link)) {
            $link = 'https://'.$link;
        }
        if (! self::isMapsLink($link)) {
            return ['error' => 'That is not a Google Maps link.'];
        }

        $url = self::expand($link);
        if ($url === null) {
            return ['error' => 'Could not open the short link. Open it in a browser and paste the full address from the address bar.'];
        }

        return self::parse($url);
    }

    public static function isMapsLink(string $url): bool
    {
        $host = (string) parse_url(trim($url), PHP_URL_HOST);

        return $host !== '' && preg_match(self::HOSTS, $host) === 1
            && (str_contains($url, 'maps') || preg_match('/goo\.gl|g\.co/i', $host));
    }

    /**
     * The name and pin in a full Maps address. Pure, for testing.
     *
     * @return array{url: string, name: ?string, latitude: ?float, longitude: ?float, cid: ?string}|array{error: string}
     */
    public static function parse(string $url): array
    {
        $decoded = rawurldecode(str_replace('+', ' ', $url));
        $name = null;
        $lat = null;
        $lng = null;

        // /maps/place/<name>/@… or /maps/search/<name>/…
        if (preg_match('#/maps/(?:place|search)/([^/@?]+)#', $url, $m)) {
            $name = trim(rawurldecode(str_replace('+', ' ', $m[1])));
        }
        // The place's own pin: !3d<lat>!4d<lng>. The "@lat,lng" is only
        // where the map was centred, so it comes second.
        if (preg_match_all('/!3d(-?\d{1,2}\.\d+)!4d(-?\d{1,3}\.\d+)/', $decoded, $all, PREG_SET_ORDER)) {
            [$lat, $lng] = [(float) end($all)[1], (float) end($all)[2]];
        } elseif (preg_match('/@(-?\d{1,2}\.\d+),(-?\d{1,3}\.\d+)/', $decoded, $m)) {
            [$lat, $lng] = [(float) $m[1], (float) $m[2]];
        }

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        foreach (['q', 'query', 'll', 'destination', 'center'] as $key) {
            $value = trim((string) ($query[$key] ?? ''));
            if ($value === '') {
                continue;
            }
            if (preg_match('/^(-?\d{1,2}\.\d+)\s*,\s*(-?\d{1,3}\.\d+)$/', $value, $m)) {
                $lat ??= (float) $m[1];
                $lng ??= (float) $m[2];
            } elseif ($key === 'q' || $key === 'query') {
                $name ??= $value;
            }
        }

        // A place's id ("0x…:0x…" in the data, or ?cid=), to know the same
        // place again; Google allows keeping place ids.
        $cid = isset($query['cid']) ? (string) $query['cid'] : null;
        if ($cid === null && preg_match('/!1s(0x[0-9a-f]+:0x[0-9a-f]+)/i', $decoded, $m)) {
            $cid = $m[1];
        }

        if ($name !== null) {
            // "Temple Name, Street, Town" from a search: the name is first.
            $name = trim(preg_replace('/\s+/', ' ', $name));
            if (preg_match('/^-?\d+\.\d+\s*,\s*-?\d+\.\d+$/', $name)) {
                $name = null;
            }
        }
        if ($lat !== null && (abs($lat) > 90 || abs($lng) > 180 || ($lat == 0.0 && $lng == 0.0))) {
            [$lat, $lng] = [null, null];
        }
        if ($name === null && $lat === null) {
            return ['error' => 'This link has no place in it. In Google Maps, open the temple, tap Share, and copy that link.'];
        }

        return ['url' => $url, 'name' => $name, 'latitude' => $lat, 'longitude' => $lng, 'cid' => $cid];
    }

    /** A short link followed to the full address, without reading any page. */
    private static function expand(string $url): ?string
    {
        for ($hop = 0; $hop < 6; $hop++) {
            $host = (string) parse_url($url, PHP_URL_HOST);
            if (! preg_match('/goo\.gl|g\.co/i', $host) && ! str_contains($host, 'consent.')) {
                return $url;
            }
            if (str_contains($host, 'consent.')) {
                // Google's cookie page carries the real address in "continue".
                parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
                $url = (string) ($q['continue'] ?? '');

                continue;
            }
            try {
                $response = Http::timeout(10)->withOptions(['allow_redirects' => false])
                    ->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/126.0 '.config('brand.name')])
                    ->get($url);
            } catch (Throwable) {
                return null;
            }
            $next = $response->header('Location');
            if ($next === '' || ! $response->redirect()) {
                return null;
            }
            $url = str_starts_with($next, '/') ? 'https://'.$host.$next : $next;
        }

        return null;
    }
}
