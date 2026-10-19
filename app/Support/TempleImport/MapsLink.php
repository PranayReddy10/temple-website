<?php

namespace App\Support\TempleImport;

use App\Support\Geocoder;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * What a Google Maps link itself says: the place's name and its pin.
 *
 * Only the link is read. A short link (maps.app.goo.gl) is followed to the
 * full address it stands for, and the name and coordinates are taken out of
 * that address; Google's page is never fetched or scraped, which its terms
 * do not allow. Phone, timings and sevas come from the temple's own
 * website instead.
 */
final class MapsLink
{
    /** Google's own hosts only: "google.co.in.example.com" is not Google. */
    private const HOSTS = '/^([a-z0-9-]+\.)*google\.(com|[a-z]{2}|co\.[a-z]{2}|com\.[a-z]{2})$|^([a-z0-9-]+\.)*goo\.gl$|^g\.co$/i';

    /** Short-link hosts, followed one redirect at a time. */
    private const SHORT_HOSTS = '/^([a-z0-9-]+\.)*goo\.gl$|^g\.co$/i';

    /** @return array{url: string, name: ?string, address: ?string, pincode: ?string, latitude: ?float, longitude: ?float, cid: ?string, located: ?string}|array{error: string} */
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

        $place = self::parse($url);
        if (isset($place['error']) || $place['latitude'] !== null) {
            return $place;
        }

        // Google's newer share links often carry the place's name (and
        // sometimes its address) but no coordinates. The map finds the pin
        // from them: the name with the address, then the address alone.
        foreach (array_unique(array_filter([
            implode(', ', array_filter([$place['name'], $place['address']])),
            $place['address'],
        ])) as $query) {
            try {
                $found = Geocoder::search($query.', India');
            } catch (Throwable) {
                break;
            }
            if ($found !== null) {
                return ['latitude' => $found['latitude'], 'longitude' => $found['longitude'], 'located' => 'Found on the map from the name and address in the link'] + $place;
            }
        }

        return $place;
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
     * @return array{url: string, name: ?string, address: ?string, pincode: ?string, latitude: ?float, longitude: ?float, cid: ?string, located: ?string}|array{error: string}
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
        $address = null;
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
        $cid = isset($query['cid']) ? (string) $query['cid'] : (isset($query['ftid']) ? (string) $query['ftid'] : null);
        if ($cid === null && preg_match('/!1s(0x[0-9a-f]+:0x[0-9a-f]+)/i', $decoded, $m)) {
            $cid = $m[1];
        }

        if ($name !== null) {
            $name = trim(preg_replace('/\s+/', ' ', $name));
            if (preg_match('/^-?\d+\.\d+\s*,\s*-?\d+\.\d+$/', $name)) {
                $name = null;
            }
        }
        // "Temple Name, Street, Town, State 508116": a shared place carries
        // its address after the name. Split only when it plainly is one (a
        // PIN code, or three parts or more), so "Kanaka Durga Temple,
        // Vijayawada" stays a name.
        if ($name !== null) {
            $parts = array_values(array_filter(array_map('trim', explode(',', $name)), fn ($p) => $p !== ''));
            if (count($parts) >= 2 && (preg_match('/\b[1-9]\d{5}\b/', $name) || count($parts) >= 3)) {
                $name = array_shift($parts);
                $address = implode(', ', $parts);
            }
        }
        $pincode = $address !== null && preg_match('/\b([1-9]\d{5})\b/', $address, $pm) ? $pm[1] : null;
        if ($lat !== null && (abs($lat) > 90 || abs($lng) > 180 || ($lat == 0.0 && $lng == 0.0))) {
            [$lat, $lng] = [null, null];
        }
        if ($name === null && $lat === null) {
            return ['error' => 'This link has no place in it. In Google Maps, open the temple, tap Share, and copy that link.'];
        }

        return ['url' => $url, 'name' => $name, 'address' => $address, 'pincode' => $pincode, 'latitude' => $lat, 'longitude' => $lng, 'cid' => $cid, 'located' => null];
    }

    /** The first full Google Maps address in a page's text (a redirect page). */
    private static function addressIn(string $html): ?string
    {
        $html = html_entity_decode(str_replace(['\\/', '\\u0026', '\\u003d'], ['/', '&', '='], $html), ENT_QUOTES | ENT_HTML5);
        if (preg_match('#https://(?:www\.|maps\.)?google\.[a-z.]{2,6}/maps[^\s"\'<>\\\\]*#i', $html, $m)) {
            return $m[0];
        }

        return null;
    }

    /** A short link followed to the full address, without reading any page. */
    private static function expand(string $url): ?string
    {
        for ($hop = 0; $hop < 6; $hop++) {
            $host = (string) parse_url($url, PHP_URL_HOST);
            $consent = preg_match('/^consent\.google\./i', $host) && preg_match(self::HOSTS, $host);
            if (! preg_match(self::SHORT_HOSTS, $host) && ! $consent) {
                return $url;
            }
            if ($consent) {
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
                // Some short links answer with a page that sends the browser
                // on: the full Maps address is in it.
                $next = $response->successful() ? self::addressIn((string) $response->body()) : null;
                if ($next === null) {
                    return null;
                }
            }
            $url = str_starts_with($next, '/') ? 'https://'.$host.$next : $next;
        }

        return null;
    }
}
