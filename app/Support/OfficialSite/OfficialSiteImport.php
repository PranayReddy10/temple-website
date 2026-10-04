<?php

namespace App\Support\OfficialSite;

use App\Models\District;
use App\Models\Temple;
use App\Models\TemplePuja;
use App\Models\TempleTiming;
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
     * the Google Maps link's name and pin, the address the map has there,
     * and the temple's own website (phone, email, timings, sevas).
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
        $lat = $found['maps']['latitude'] ?? ($temple->hasCoordinates() ? (float) $temple->latitude : null);
        $lng = $found['maps']['longitude'] ?? ($temple->hasCoordinates() ? (float) $temple->longitude : null);

        if (filled($url)) {
            $site = OfficialSiteReader::read((string) $url, $temple->official_site_pages ?? []);
            isset($site['error']) ? $problems[] = $site['error'] : $found = $site + $found;
        }
        if ($found === []) {
            return ['error' => $problems !== [] ? implode(' ', $problems) : 'Give the temple\'s Google Maps link or its website.'];
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
            // The source of what was taken, so the listing says where it is from.
            $fromSite = filled($found['source_url'] ?? null);
            $temple->forceFill([
                'source_name' => $temple->source_name ?: ($fromSite ? 'Official website' : 'Map location'),
                'source_url' => blank($temple->source_url) && $fromSite ? mb_substr($found['source_url'], 0, 255) : $temple->source_url,
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
