<?php

namespace App\Support\OfficialSite;

use App\Models\District;
use App\Models\Temple;
use App\Models\TemplePuja;
use App\Models\TempleTiming;
use App\Services\Osm\OsmTempleImporter;
use App\Services\Osm\OverpassClient;
use App\Services\Osm\TempleWikipediaFinder;
use App\Support\Geocoder;
use App\Support\TempleImport\MapsLink;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Reads a temple's own website into `official_import`, and puts into the
 * listing only what a staff member ticked on the review screen.
 */
final class OfficialSiteImport
{
    /**
     * Reads what the links say about a temple and keeps it for review:
     *
     * 1. the Google Maps link: the temple's name and pin;
     * 2. OpenStreetMap at that pin: the temple's own map record (phone,
     *    website, its Wikidata and Wikipedia references), and the address,
     *    PIN code, district and state there;
     * 3. the temple's website (the one given, else the one OpenStreetMap
     *    lists): phone, email, timings and sevas;
     * 4. Wikipedia: the temple's article and its opening paragraph.
     *
     * Returns the reading, or ['error' => …] when nothing could be read.
     *
     * @param  array<int, string>|null  $extraPages  more pages to read; saved on the temple for every re-read
     */
    public static function read(Temple $temple, ?string $url = null, ?array $extraPages = null, ?string $mapsUrl = null): array
    {
        $url = filled($url) ? $url : $temple->official_website;
        if ($extraPages !== null) {
            $extraPages = array_values(array_unique(array_filter(array_map(
                fn ($u) => OfficialSiteReader::normaliseUrl((string) $u), $extraPages))));
            $temple->official_site_pages = $extraPages === [] ? null : $extraPages;
        }

        $found = [];
        $problems = [];
        if (filled($mapsUrl)) {
            $maps = MapsLink::read((string) $mapsUrl);
            // Only what the link says is kept: the name and the pin. The
            // link itself is not stored; it can run to a thousand characters.
            isset($maps['error']) ? $problems[] = $maps['error'] : $found['maps'] = array_diff_key($maps, ['url' => true]);
        }
        $name = $found['maps']['name'] ?? $temple->name;
        $lat = $found['maps']['latitude'] ?? ($temple->hasCoordinates() ? (float) $temple->latitude : null);
        $lng = $found['maps']['longitude'] ?? ($temple->hasCoordinates() ? (float) $temple->longitude : null);

        // The temple's own record on OpenStreetMap, at the pin.
        if ($lat !== null && $lng !== null && ($osm = self::osmAt($lat, $lng, (string) $name)) !== null) {
            $found['osm'] = $osm;
        }

        // Its website: the one given, else the one OpenStreetMap lists.
        $url = filled($url) ? $url : ($found['osm']['website'] ?? null);
        if (filled($url)) {
            $site = OfficialSiteReader::read((string) $url, $temple->official_site_pages ?? []);
            isset($site['error']) ? $problems[] = $site['error'] : $found = $site + $found;
        }
        if ($found === []) {
            return ['error' => $problems !== [] ? implode(' ', $problems) : 'Give the temple\'s Google Maps link or its website.'];
        }

        // OpenStreetMap's phone and email where the website had none.
        foreach (['phone' => 'phones', 'email' => 'emails'] as $tag => $list) {
            if (empty($found[$list]) && filled($found['osm'][$tag] ?? null)) {
                $found[$list] = [$found['osm'][$tag]];
            }
        }

        // The Maps pin is where the temple is; the website's map comes next.
        $lat ??= $found['latitude'] ?? null;
        $lng ??= $found['longitude'] ?? null;
        if (isset($found['maps']['latitude'])) {
            [$found['latitude'], $found['longitude']] = [$found['maps']['latitude'], $found['maps']['longitude']];
        }
        if ($lat !== null && $lng !== null && ($place = self::placeAt($lat, $lng))) {
            $found['place'] = $place;
        }

        // Wikipedia: the article OpenStreetMap or Wikidata names, else an
        // article near the pin whose title names the temple.
        try {
            $finder = app(TempleWikipediaFinder::class);
            $article = $finder->articleForPlace(
                $found['osm']['wikipedia_url'] ?? $temple->wikipedia_url,
                $found['osm']['wikidata'] ?? $temple->wikidata_id,
                $lat, $lng, array_values(array_unique(array_filter([$name, $temple->name, $found['osm']['name'] ?? null]))),
            );
            if ($article !== null && ($summary = $finder->summary(...$article)) !== null) {
                $found['wikipedia'] = $summary + ['opening' => $finder->opening($summary['extract'])]
                    + $finder->sections($summary['lang'], $summary['title']);
            }
        } catch (Throwable) {
            $problems[] = 'Wikipedia could not be reached this time.';
        }

        if ($problems !== []) {
            $found['warning'] = trim(($found['warning'] ?? '').' '.implode(' ', $problems));
        }
        $found['read_at'] ??= now()->toIso8601String();
        $found = array_filter($found, fn ($v) => $v !== null && $v !== []);

        $temple->forceFill([
            'official_import' => $found,
            'official_import_at' => now(),
            'official_import_reviewed_at' => null,
        ]);
        if (blank($temple->official_website) && filled($url) && isset($site) && ! isset($site['error'])) {
            $temple->official_website = mb_substr((string) ($site['source_url'] ?? $url), 0, 255);
        }
        $temple->saveQuietly();

        return $found;
    }

    /**
     * The temple's record on OpenStreetMap: the place of worship within
     * 250 m whose name is this temple's, or the only Hindu one there.
     *
     * @return array<string, mixed>|null
     */
    public static function osmAt(float $lat, float $lng, string $name): ?array
    {
        try {
            $elements = app(OverpassClient::class)->near($lat, $lng);
        } catch (Throwable) {
            return null;
        }
        $importer = app(OsmTempleImporter::class);
        $named = collect($elements)->filter(fn ($e) => collect([$e['tags']['name'] ?? '', $e['tags']['name:en'] ?? ''])
            ->filter()->contains(fn ($n) => $importer->sameName($name, $n)));
        $hindu = collect($elements)->filter(fn ($e) => ($e['tags']['religion'] ?? '') === 'hindu');
        $element = $named->first() ?? ($hindu->count() === 1 ? $hindu->first() : null);
        if ($element === null) {
            return null;
        }
        $tags = $element['tags'];
        $website = $tags['website'] ?? $tags['contact:website'] ?? null;

        return array_filter([
            'ref' => $element['type'].'/'.$element['id'],
            'url' => 'https://www.openstreetmap.org/'.$element['type'].'/'.$element['id'],
            'name' => $importer->name($tags),
            'phone' => isset($tags['phone']) || isset($tags['contact:phone']) ? trim(explode(';', $tags['phone'] ?? $tags['contact:phone'])[0]) : null,
            'email' => $tags['email'] ?? $tags['contact:email'] ?? null,
            'website' => $website !== null && preg_match('#^https?://#i', $website) ? $website : null,
            'wikidata' => preg_match('/^Q\d+$/', $tags['wikidata'] ?? '') === 1 ? $tags['wikidata'] : null,
            'wikipedia_url' => $importer->wikipediaUrl($tags),
            'commons_image' => $importer->commonsFile($tags),
            'opening_hours' => $tags['opening_hours'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /**
     * The address the map has at a pin, with the state and district matched
     * to our own lists.
     *
     * @return array<string, mixed>|null
     */
    public static function placeAt(float $lat, float $lng): ?array
    {
        try {
            $place = Geocoder::reverse($lat, $lng);
        } catch (Throwable) {
            return null;
        }
        if ($place === null) {
            return null;
        }
        $district = null;
        if (($place['state_id'] ?? null) && filled($place['district'] ?? null)) {
            $name = mb_strtolower(trim(preg_replace('/\s+district$/i', '', $place['district'])));
            $district = District::where('state_id', $place['state_id'])
                ->get(['id', 'name'])
                ->first(fn (District $d) => mb_strtolower(trim(preg_replace('/\s+district$/i', '', $d->name))) === $name);
        }

        return array_filter([
            'address' => $place['address'] ?? null,
            'pincode' => $place['pincode'] ?? null,
            'city' => $place['city'] ?? null,
            'state' => $place['state'] ?? null,
            'state_id' => $place['state_id'] ?? null,
            'district' => $place['district'] ?? null,
            'district_id' => $district?->id,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /** A one-line account of what a reading holds. */
    public static function summary(?array $found): string
    {
        if (! $found) {
            return 'Nothing read yet';
        }

        $pages = count($found['pages'] ?? []);

        $what = collect([
            count($found['timings'] ?? []) ? count($found['timings']).' timings' : null,
            count($found['sevas'] ?? []) ? count($found['sevas']).' sevas with fees' : null,
            count($found['phones'] ?? []) ? 'phone' : null,
            count($found['emails'] ?? []) ? 'email' : null,
            isset($found['pincode']) ? 'PIN code' : null,
            isset($found['latitude']) ? 'map location' : null,
            isset($found['place']) ? 'address from the map' : null,
            isset($found['osm']) ? 'OpenStreetMap record' : null,
            isset($found['wikipedia']) ? 'Wikipedia article' : null,
        ])->filter()->implode(', ');

        return ($what !== '' ? $what : 'No details found').($pages > 1 ? ' on '.$pages.' pages' : ' on the site');
    }

    /**
     * Applies what was ticked.
     *
     * @param  array<string, mixed>  $data  form state: use_* toggles with their edited values, and the
     *                                      timings and sevas rows, each with "take" and the values as corrected
     * @return array<int, string> what changed, for the message
     */
    public static function apply(Temple $temple, array $data): array
    {
        $found = $temple->official_import ?? [];
        $done = [];

        DB::transaction(function () use ($temple, $data, $found, &$done): void {
            $fields = [];
            foreach (['contact_phone', 'contact_email', 'address', 'pincode', 'city', 'short_description'] as $field) {
                if (! empty($data['use_'.$field]) && filled($data[$field] ?? null)) {
                    $fields[$field] = $data[$field];
                }
            }
            if (! empty($data['use_location']) && is_numeric($data['latitude'] ?? null) && is_numeric($data['longitude'] ?? null)) {
                $fields['latitude'] = (float) $data['latitude'];
                $fields['longitude'] = (float) $data['longitude'];
            }
            // The state and district the map has there, matched to our lists.
            if (! empty($data['use_region']) && is_numeric($data['state_id'] ?? null)) {
                $fields['state_id'] = (int) $data['state_id'];
                $fields['district_id'] = is_numeric($data['district_id'] ?? null) ? (int) $data['district_id'] : null;
            }
            if ($fields !== []) {
                $temple->fill($fields);
                $done[] = count($fields).' '.(count($fields) === 1 ? 'detail' : 'details');
            }
            // References: the temple's OpenStreetMap record and Wikipedia
            // article, each only where the slot is empty and no other
            // temple already has it.
            $refs = [];
            $free = fn (string $column, ?string $value): bool => filled($value) && blank($temple->getAttribute($column))
                && ! Temple::withTrashed()->where($column, $value)->whereKeyNot($temple->getKey())->exists();
            $osm = $found['osm'] ?? [];
            $wiki = $found['wikipedia'] ?? [];
            if (! empty($data['use_osm']) && $osm !== []) {
                foreach (['osm_ref' => $osm['ref'] ?? null, 'wikidata_id' => $osm['wikidata'] ?? null, 'commons_image' => $osm['commons_image'] ?? null] as $column => $value) {
                    if ($free($column, $value)) {
                        $refs[$column] = $value;
                    }
                }
            }
            if (! empty($data['use_wikipedia_link']) && $wiki !== []) {
                if (blank($temple->wikipedia_url)) {
                    $refs['wikipedia_url'] = mb_substr($wiki['url'], 0, 500);
                }
                if (! isset($refs['wikidata_id']) && $free('wikidata_id', $wiki['wikidata'] ?? null)) {
                    $refs['wikidata_id'] = $wiki['wikidata'];
                }
            }
            // The article's opening as the description, credited to
            // Wikipedia wherever it is shown (CC BY-SA).
            if (! empty($data['use_wikipedia_description']) && empty($data['use_short_description']) && filled($data['wikipedia_description'] ?? null) && $wiki !== []) {
                $refs['short_description'] = trim($data['wikipedia_description']);
                $refs['description_source'] = 'wikipedia';
                $refs['wikipedia_url'] = $temple->wikipedia_url ?: mb_substr($wiki['url'], 0, 500);
                $done[] = 'description from Wikipedia';
            }
            // History and significance from the article's sections.
            $taken = $temple->wikipedia_fields ?? [];
            foreach (['history', 'significance'] as $field) {
                if (! empty($data['use_wikipedia_'.$field]) && filled($data['wikipedia_'.$field] ?? null) && $wiki !== []) {
                    $refs[$field] = trim($data['wikipedia_'.$field]);
                    $taken[] = $field;
                    $refs['wikipedia_url'] ??= $temple->wikipedia_url ?: mb_substr($wiki['url'], 0, 500);
                    $done[] = $field.' from Wikipedia';
                }
            }
            if ($taken !== ($temple->wikipedia_fields ?? [])) {
                $refs['wikipedia_fields'] = array_values(array_unique($taken));
            }
            if (array_intersect_key($refs, array_flip(['osm_ref', 'wikipedia_url', 'wikidata_id'])) !== []) {
                $done[] = 'map and Wikipedia links';
            }
            $temple->forceFill($refs);

            // The source of what was taken, so the listing says where it is from.
            $fromSite = filled($found['source_url'] ?? null);
            $fromOsm = ! $fromSite && ! empty($data['use_osm']) && isset($osm['url']);
            $temple->forceFill([
                'source_name' => $temple->source_name ?: ($fromSite ? 'Official website' : ($fromOsm ? OsmTempleImporter::SOURCE_NAME : 'Map location')),
                'source_url' => blank($temple->source_url) && ($fromSite || $fromOsm) ? mb_substr($fromSite ? $found['source_url'] : $osm['url'], 0, 255) : $temple->source_url,
                'official_import_reviewed_at' => now(),
                'last_verified_at' => $fields !== [] ? now() : $temple->last_verified_at,
            ])->save();

            $timings = collect($data['timings'] ?? [])
                ->filter(fn ($t) => is_array($t) && ! empty($t['take']) && filled($t['label'] ?? null))
                ->map(fn ($t) => [
                    'kind' => in_array($t['kind'] ?? null, ['general', 'darshan', 'aarti', 'special'], true) ? $t['kind'] : 'general',
                    'label' => mb_substr(trim($t['label']), 0, 120),
                    'days' => TempleTiming::normaliseDays($t['days'] ?? null),
                    'opens_at' => OfficialSiteReader::fromTwelveHour($t['opens_at'] ?? null),
                    'closes_at' => OfficialSiteReader::fromTwelveHour($t['closes_at'] ?? null),
                    'notes' => filled($t['notes'] ?? null) ? trim($t['notes']) : null,
                ])
                ->filter(fn ($t) => $t['opens_at'] !== null || $t['closes_at'] !== null);
            if ($timings->isNotEmpty()) {
                if (! empty($data['replace_timings'])) {
                    $temple->timings()->delete();
                }
                $order = (int) $temple->timings()->max('sort_order');
                foreach ($timings as $t) {
                    TempleTiming::create($t + ['temple_id' => $temple->id, 'sort_order' => ++$order]);
                }
                $done[] = $timings->count().' timings';
            }

            $sevas = collect($data['sevas'] ?? [])
                ->filter(fn ($s) => is_array($s) && ! empty($s['take']) && filled($s['name'] ?? null) && is_numeric($s['fee'] ?? null));
            $order = (int) $temple->pujas()->max('sort_order');
            foreach ($sevas as $s) {
                $name = mb_substr(trim($s['name']), 0, 150);
                $details = array_filter([
                    'starts_at' => OfficialSiteReader::fromTwelveHour($s['starts_at'] ?? null),
                    'duration_minutes' => is_numeric($s['duration_minutes'] ?? null) ? (int) $s['duration_minutes'] : null,
                    'schedule_note' => filled($s['note'] ?? null) ? mb_substr(trim($s['note']), 0, 250) : null,
                ], fn ($v) => $v !== null);
                $existing = $temple->pujas()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first();
                if ($existing) {
                    // The fee is updated; what staff already wrote is kept.
                    $existing->update(['fee_amount' => (int) $s['fee'], 'is_free' => false]
                        + array_filter($details, fn ($v, $k) => blank($existing->{$k}), ARRAY_FILTER_USE_BOTH));
                } else {
                    TemplePuja::create([
                        'temple_id' => $temple->id, 'name' => $name,
                        'kind' => in_array($s['kind'] ?? null, ['puja', 'seva', 'prasadam'], true) ? $s['kind'] : 'seva',
                        'fee_amount' => (int) $s['fee'], 'is_free' => false, 'sort_order' => ++$order,
                        // Listed for devotees only when staff said so; in-app
                        // booking stays off until the temple turns it on.
                        'is_published' => ! empty($data['publish_sevas']),
                        'app_booking_enabled' => false,
                    ] + $details);
                }
            }
            if ($sevas->isNotEmpty()) {
                $done[] = $sevas->count().' sevas';
            }
        });

        return $done;
    }
}
