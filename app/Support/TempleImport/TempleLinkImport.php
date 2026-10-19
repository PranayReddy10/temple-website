<?php

namespace App\Support\TempleImport;

use App\Enums\TempleStatus;
use App\Models\District;
use App\Models\Temple;
use App\Support\OfficialSite\OfficialSiteImport;
use App\Support\OfficialSite\OfficialSiteReader;
use Illuminate\Support\Str;

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
        if (! isset($found['error'])) {
            self::fillDraft($temple, $found, $maps);
        }

        return [
            'status' => 'created',
            'message' => $temple->name.': '.(isset($found['error']) ? $found['error'] : OfficialSiteImport::summary($found)),
            'temple' => $temple,
        ];
    }

    /**
     * A new draft takes its place at once: the address and PIN code the
     * link carries, else what the map has at the pin (or the website
     * says), with the town, state and district. Staff check it before
     * publishing, as with every draft; the website's timings and sevas
     * still wait for review.
     *
     * @param  array<string, mixed>  $found
     * @param  array<string, mixed>|null  $maps
     */
    private static function fillDraft(Temple $temple, array $found, ?array $maps): void
    {
        $place = $found['place'] ?? [];
        $pin = fn (?string $p) => $p !== null && preg_match('/^[1-9]\d{5}$/', $p) ? $p : null;
        $fill = array_filter([
            'address' => $maps['address'] ?? $place['address'] ?? $found['address'] ?? null,
            'pincode' => $pin($maps['pincode'] ?? null) ?? $pin($place['pincode'] ?? null) ?? $pin($found['pincode'] ?? null),
            'city' => $place['city'] ?? null,
            'latitude' => $temple->latitude === null ? ($found['latitude'] ?? null) : null,
            'longitude' => $temple->longitude === null ? ($found['longitude'] ?? null) : null,
            'state_id' => $place['state_id'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        if (isset($fill['state_id'])) {
            $district = $place['district_id'] ?? null;
            if ($district === null && filled($place['district'] ?? null)) {
                $name = trim(preg_replace('/\s+district$/i', '', (string) $place['district']));
                $district = District::firstOrCreate(
                    ['state_id' => $fill['state_id'], 'slug' => Str::slug($name)],
                    ['name' => $name],
                )->id;
            }
            $fill['district_id'] = $district;
        }

        $temple->forceFill(array_filter($fill, fn ($v) => $v !== null))->save();
    }

    /** A temple already listed at that place, or with the same website. */
    public static function existing(?array $maps, ?string $website, string $name): ?Temple
    {
        // Many temples share one portal (the AP Endowments one), so a site
        // is told apart by its temple code there, not by its host alone.
        if (filled($website) && ($key = OfficialSiteReader::siteKey($website))) {
            $host = explode('/', $key)[0];
            $t = Temple::where('official_website', 'like', '%'.$host.'%')->get()
                ->first(fn (Temple $t) => OfficialSiteReader::siteKey((string) $t->official_website) === $key);
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
