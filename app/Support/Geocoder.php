<?php

namespace App\Support;

use App\Models\State;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Coordinates turned into an address, and a PIN code turned into a place,
 * through OpenStreetMap's Nominatim.
 *
 * Two jobs. When a devotee standing at a temple taps "I'm here", the pin is
 * all they should have to give: the PIN code, village, district and state
 * are read off the map rather than typed. And when India Post's directory
 * does not know a PIN code (it misses real ones, and it goes down), the map
 * is the second opinion before we tell anyone "no post office has that code".
 *
 * Asked from here rather than from the app so answers are cached for
 * everybody, the app needs no third-party host allowed, and the state comes
 * back already matched to our own states table. Nominatim's usage policy
 * asks for a real User-Agent and no more than one request a second; the
 * cache keeps a busy day well inside that.
 */
final class Geocoder
{
    public const SOURCE = 'https://nominatim.openstreetmap.org';

    /**
     * The address at a point.
     *
     * @return array{pincode: ?string, state: ?string, state_id: ?int, district: ?string, city: ?string, address: ?string, places: array<int, array{name: string, block: ?string}>, latitude: float, longitude: float}|null
     *         null when the map has nothing there
     *
     * @throws GeocoderUnavailable when the map service cannot be reached
     */
    public static function reverse(float $latitude, float $longitude): ?array
    {
        // Four decimals is about eleven metres: the same temple courtyard
        // gives the same answer without a fresh request for every footstep.
        $key = sprintf('geocode:reverse:%.4f,%.4f', $latitude, $longitude);
        $cached = Cache::get($key);

        if ($cached !== null) {
            return $cached === false ? null : $cached;
        }

        $json = self::ask('/reverse', [
            'lat' => $latitude,
            'lon' => $longitude,
            'zoom' => 18,
        ]);

        if ($json === null || ! is_array($json['address'] ?? null)) {
            Cache::put($key, false, now()->addDay());

            return null;
        }

        $result = self::place($json['address']) + [
            'latitude' => $latitude,
            'longitude' => $longitude,
        ];

        Cache::put($key, $result, now()->addDays(30));

        return $result;
    }

    /**
     * Where a PIN code is, from the map, in the shape PincodeLookup answers.
     *
     * @return array{pincode: string, state: ?string, state_id: ?int, district: ?string, places: array<int, array{name: string, block: ?string}>}|null
     *
     * @throws GeocoderUnavailable
     */
    public static function postcode(string $pincode): ?array
    {
        $json = self::ask('/search', [
            'postalcode' => $pincode,
            'country' => 'India',
            'limit' => 5,
        ]);

        $rows = collect(is_array($json) ? $json : [])
            ->filter(fn ($row): bool => is_array($row) && is_array($row['address'] ?? null));

        if ($rows->isEmpty()) {
            return null;
        }

        $first = self::place($rows->first()['address']);

        $places = $rows
            ->map(fn (array $row): ?string => self::place($row['address'])['city'])
            ->filter()
            ->unique()
            ->values()
            ->map(fn (string $name): array => ['name' => $name, 'block' => null])
            ->all();

        return [
            'pincode' => $pincode,
            'state' => $first['state'],
            'state_id' => $first['state_id'],
            'district' => $first['district'],
            'places' => $places,
        ];
    }

    /**
     * Nominatim's address parts, read into ours.
     *
     * The map names the same thing differently from place to place: a
     * district is `state_district` in most of India and `county` elsewhere;
     * the settlement is whichever of village, town, city or suburb it has.
     *
     * @param  array<string, mixed>  $address
     * @return array{pincode: ?string, state: ?string, state_id: ?int, district: ?string, city: ?string, address: ?string, places: array<int, array{name: string, block: ?string}>}
     */
    private static function place(array $address): array
    {
        $pick = fn (string ...$keys): ?string => collect($keys)
            ->map(fn (string $k) => $address[$k] ?? null)
            ->first(fn ($v): bool => is_string($v) && trim($v) !== '');

        $postcode = $pick('postcode');
        $postcode = $postcode !== null && preg_match('/^[1-9][0-9]{5}$/', $postcode) === 1 ? $postcode : null;

        $stateName = $pick('state');
        $state = $stateName === null
            ? null
            : State::query()->whereRaw('lower(name) = ?', [mb_strtolower($stateName)])->first();

        $district = $pick('state_district', 'county', 'district');
        // "Warangal District" on the map is "Warangal" in our table and on
        // every form; the word adds nothing a devotee needs to type.
        $district = $district === null ? null : trim(preg_replace('/\s+district$/i', '', $district) ?? $district);

        $city = $pick('village', 'town', 'city', 'municipality', 'hamlet', 'suburb', 'city_district');

        $street = collect([$pick('house_number'), $pick('road'), $pick('neighbourhood', 'quarter', 'suburb')])
            ->filter()
            ->unique()
            ->reject(fn (string $part): bool => $part === $city)
            ->implode(', ');

        return [
            'pincode' => $postcode,
            'state' => $state?->name ?? $stateName,
            'state_id' => $state?->getKey(),
            'district' => $district,
            'city' => $city,
            'address' => $street !== '' ? $street : null,
            'places' => $city === null ? [] : [['name' => $city, 'block' => null]],
        ];
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<mixed>|null null when the map has no answer
     *
     * @throws GeocoderUnavailable
     */
    private static function ask(string $path, array $query): ?array
    {
        try {
            $response = Http::timeout(8)
                ->acceptJson()
                ->withHeaders([
                    // Nominatim refuses anonymous clients; the brand and the
                    // support address say who is asking.
                    'User-Agent' => config('brand.name').' ('.config('brand.support_email').')',
                ])
                ->get(self::SOURCE.$path, $query + ['format' => 'jsonv2', 'addressdetails' => 1, 'accept-language' => 'en']);
        } catch (Throwable $e) {
            throw new GeocoderUnavailable('The map service could not be reached.', previous: $e);
        }

        if (! $response->successful()) {
            throw new GeocoderUnavailable('The map service answered '.$response->status().'.');
        }

        $json = $response->json();

        // Nominatim says "no result" with a 200 and an error key.
        if (! is_array($json) || isset($json['error'])) {
            return null;
        }

        return $json;
    }
}
