<?php

namespace App\Support\TempleImport;

use App\Enums\TempleStatus;
use App\Models\Temple;
use App\Support\OfficialSite\OfficialSiteImport;
use App\Support\OfficialSite\OfficialSiteReader;

/**
 * New temples from links: a Google Maps link, and the temple's website if
 * it has one. Each becomes a draft with its pin, read for details,
 * waiting in "Imported details to review"; a temple already listed at
 * that spot is not added twice.
 */
final class TempleLinkImport
{
    /** Temples closer than this are the same place. */
    public const SAME_PLACE_METRES = 60;

    /** Closer than this with a similar name is the same temple. */
    public const NEARBY_METRES = 300;

    /**
     * One pasted line: a Maps link, a website, and any other words as the
     * temple's name ("Sri Rama Temple https://maps.app.goo.gl/… https://…").
     *
     * @return array{maps: ?string, website: ?string, name: ?string}
     */
    public static function parseLine(string $line): array
    {
        // CSV columns: a comma before the next link separates them (Maps
        // links have commas of their own, inside "@lat,lng").
        $line = preg_replace('#[,;]\s*(?=https?://|www\.|maps\.app\.goo\.gl/)#i', ' ', $line);
        preg_match_all('#https?://\S+|(?:www\.|maps\.app\.goo\.gl/|goo\.gl/)\S+#i', $line, $m);
        $maps = null;
        $website = null;
        foreach ($m[0] as $url) {
            $url = rtrim($url, ',;|)');
            if ($maps === null && MapsLink::isMapsLink(preg_match('#^https?://#i', $url) ? $url : 'https://'.$url)) {
                $maps = $url;
            } elseif ($website === null) {
                $website = $url;
            }
        }
        $name = trim(preg_replace('/\s+/', ' ', str_replace($m[0], ' ', $line)), " \t,;|-–");

        return ['maps' => $maps, 'website' => $website, 'name' => $name !== '' ? $name : null];
    }

    /**
     * @return array{status: 'created'|'duplicate'|'error', message: string, temple?: Temple}
     */
    public static function create(?string $mapsUrl, ?string $website = null, ?string $name = null): array
    {
        if (blank($mapsUrl) && blank($website)) {
            return ['status' => 'error', 'message' => 'No link given.'];
        }
        $maps = null;
        if (filled($mapsUrl)) {
            $maps = MapsLink::read($mapsUrl);
            if (isset($maps['error'])) {
                return ['status' => 'error', 'message' => $maps['error']];
            }
        }
        $site = null;
        if (filled($website) && ($maps === null || ($name ?? $maps['name']) === null)) {
            $site = OfficialSiteReader::read($website);
        }
        $name = $name ?? $maps['name'] ?? (isset($site['error']) ? null : ($site['name'] ?? null));
        if ($name === null) {
            return ['status' => 'error', 'message' => 'The link has no temple name. Add the name before the link, or use the temple\'s place link from Google Maps.'];
        }

        if ($same = self::existing($maps, $website, $name)) {
            return ['status' => 'duplicate', 'message' => '"'.$name.'" looks like '.$same->name.', already listed.', 'temple' => $same];
        }

        $temple = Temple::create([
            'name' => mb_substr($name, 0, 255),
            'status' => TempleStatus::Draft,
            'latitude' => $maps['latitude'] ?? null,
            'longitude' => $maps['longitude'] ?? null,
            'official_website' => $website ? mb_substr((string) OfficialSiteReader::normaliseUrl($website), 0, 255) ?: null : null,
            // Settled on review: the website, OpenStreetMap or the map pin.
            'source_name' => $website ? 'Official website' : null,
        ]);

        $found = OfficialSiteImport::read($temple, $website, null, $mapsUrl);

        return [
            'status' => 'created',
            'message' => $temple->name.': '.(isset($found['error']) ? $found['error'] : OfficialSiteImport::summary($found)),
            'temple' => $temple,
        ];
    }

    /** A temple already listed at that place, or with the same website. */
    public static function existing(?array $maps, ?string $website, string $name): ?Temple
    {
        if (filled($website) && ($url = OfficialSiteReader::normaliseUrl($website))) {
            $host = preg_replace('/^www\./', '', (string) parse_url($url, PHP_URL_HOST));
            $t = Temple::where('official_website', 'like', '%'.$host.'%')->get()
                ->first(fn (Temple $t) => preg_replace('/^www\./', '', (string) parse_url((string) $t->official_website, PHP_URL_HOST)) === $host);
            if ($t) {
                return $t;
            }
        }
        $lat = $maps['latitude'] ?? null;
        $lng = $maps['longitude'] ?? null;
        if ($lat === null || $lng === null) {
            return null;
        }
        $box = 0.004; // about 400 m
        $words = self::words($name);

        return Temple::query()
            ->whereBetween('latitude', [$lat - $box, $lat + $box])
            ->whereBetween('longitude', [$lng - $box, $lng + $box])
            ->get()
            ->map(fn (Temple $t) => [$t, self::metres($lat, $lng, (float) $t->latitude, (float) $t->longitude)])
            ->filter(fn ($pair) => $pair[1] <= self::SAME_PLACE_METRES
                || ($pair[1] <= self::NEARBY_METRES && count(array_intersect($words, self::words($pair[0]->name))) > 0))
            ->sortBy(fn ($pair) => $pair[1])
            ->first()[0] ?? null;
    }

    /** @return array<int, string> the words that tell temples apart */
    private static function words(string $name): array
    {
        return array_values(array_filter(
            preg_split('/[^\p{L}]+/u', mb_strtolower($name)),
            fn ($w) => mb_strlen($w) > 3 && ! in_array($w, ['temple', 'swamy', 'swami', 'devasthanam', 'mandir', 'mandiram', 'gudi', 'sree', 'shri', 'sri'], true),
        ));
    }

    private static function metres(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(min(1, sqrt($a)));
    }
}
