<?php

namespace App\Services\Osm;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Asks OpenStreetMap, through the Overpass API, for a state's districts and
 * the Hindu places of worship mapped inside each one.
 *
 * OSM data is open under the ODbL: it may be stored and republished as long
 * as it is credited to "OpenStreetMap contributors", which is why every
 * temple imported from here carries that as its source.
 */
class OverpassClient
{
    /**
     * The state's districts (admin level 5 in India).
     *
     * @return list<array{id: int, name: string}>
     */
    public function districts(string $isoCode): array
    {
        $query = <<<OQL
            [out:json][timeout:120];
            area["ISO3166-2"="{$isoCode}"]["admin_level"="4"]->.state;
            rel(area.state)["boundary"="administrative"]["admin_level"="5"];
            out tags;
            OQL;

        return collect($this->run($query))
            ->map(fn (array $e): array => [
                'id' => (int) $e['id'],
                'name' => (string) ($e['tags']['name:en'] ?? $e['tags']['name'] ?? ''),
            ])
            ->filter(fn (array $d): bool => $d['name'] !== '')
            ->sortBy('name')
            ->values()
            ->all();
    }

    /**
     * Every Hindu place of worship inside a district boundary. Buildings and
     * temple compounds are mapped as ways or relations; "center" gives those
     * a single point.
     *
     * @return list<array{type: string, id: int, lat: float, lon: float, tags: array<string, string>}>
     */
    public function temples(int $districtRelationId): array
    {
        // Overpass numbers an area made from relation R as 3600000000 + R.
        $area = 3600000000 + $districtRelationId;

        $query = <<<OQL
            [out:json][timeout:180];
            area({$area})->.district;
            nwr(area.district)["amenity"="place_of_worship"]["religion"="hindu"];
            out center tags;
            OQL;

        return collect($this->run($query))
            ->map(function (array $e): ?array {
                $lat = $e['lat'] ?? $e['center']['lat'] ?? null;
                $lon = $e['lon'] ?? $e['center']['lon'] ?? null;

                if ($lat === null || $lon === null) {
                    return null;
                }

                return [
                    'type' => (string) $e['type'],
                    'id' => (int) $e['id'],
                    'lat' => (float) $lat,
                    'lon' => (float) $lon,
                    'tags' => array_map('strval', $e['tags'] ?? []),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * The named villages, towns and city neighbourhoods in a district. Few
     * temples on OSM carry an address, so the nearest of these is how an
     * imported temple gets its locality.
     *
     * @return list<array{name: string, lat: float, lon: float}>
     */
    public function places(int $districtRelationId): array
    {
        $area = 3600000000 + $districtRelationId;

        $query = <<<OQL
            [out:json][timeout:120];
            area({$area})->.district;
            node(area.district)["place"~"^(city|town|village|suburb|hamlet|neighbourhood)$"]["name"];
            out;
            OQL;

        return collect($this->run($query))
            ->map(fn (array $e): array => [
                'name' => (string) ($e['tags']['name:en'] ?? $e['tags']['name']),
                'lat' => (float) $e['lat'],
                'lon' => (float) $e['lon'],
            ])
            ->values()
            ->all();
    }

    /**
     * Places of worship within a few hundred metres of a pin, for importing
     * one temple while staff wait: a short timeout and no retries.
     *
     * @return list<array{type: string, id: int, lat: float, lon: float, tags: array<string, string>}>
     */
    public function near(float $lat, float $lon, int $metres = 250): array
    {
        $query = <<<OQL
            [out:json][timeout:20];
            nwr(around:{$metres},{$lat},{$lon})["amenity"="place_of_worship"];
            out center tags;
            OQL;

        $elements = Http::withHeaders([
            'User-Agent' => config('brand.name').'/1.0 ('.config('brand.url').'; '.config('brand.support_email').')',
        ])->timeout(25)->asForm()
            ->post((string) config('services.overpass.url'), ['data' => $query])
            ->throw()
            ->json('elements') ?? [];

        return collect($elements)
            ->map(function (array $e): ?array {
                $elat = $e['lat'] ?? $e['center']['lat'] ?? null;
                $elon = $e['lon'] ?? $e['center']['lon'] ?? null;

                return $elat === null || $elon === null ? null : [
                    'type' => (string) $e['type'], 'id' => (int) $e['id'],
                    'lat' => (float) $elat, 'lon' => (float) $elon,
                    'tags' => array_map('strval', $e['tags'] ?? []),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    protected function run(string $query): array
    {
        return $this->client()
            ->asForm()
            ->post((string) config('services.overpass.url'), ['data' => $query])
            ->throw()
            ->json('elements') ?? [];
    }

    protected function client(): PendingRequest
    {
        // Overpass asks every client to identify itself, and answers 429 when
        // it is busy; a short wait and a retry is the polite response.
        return Http::withHeaders([
            'User-Agent' => config('brand.name').'/1.0 ('.config('brand.url').'; '.config('brand.support_email').')',
        ])
            ->timeout(200)
            ->retry(3, 10_000, fn ($e) => $e instanceof RequestException && in_array($e->response->status(), [429, 502, 503, 504], true), throw: true);
    }
}
