<?php

namespace App\Support;

use App\Models\State;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * An Indian PIN code, turned into the state, district and towns it covers.
 *
 * Answered by India Post's directory through api.postalpincode.in, asked from
 * here rather than from the app so the answer is cached for everybody, the
 * app needs no third-party host allowed, and the state comes back already
 * matched to our own states table.
 *
 * India Post's directory is the first word, not the last. It misses real
 * codes and it goes down, and either way it used to become "No post office
 * has that PIN code" on a devotee's screen while they stood in a village
 * that plainly has one. So a miss there is checked against the map before
 * anyone is told the code is wrong, and a directory that cannot be reached
 * at all is reported as exactly that.
 */
final class PincodeLookup
{
    public const SOURCE = 'https://api.postalpincode.in/pincode/';

    /**
     * @return array{pincode: string, state: ?string, state_id: ?int, district: ?string, places: array<int, array{name: string, block: ?string}>}|null
     *         null when the code does not exist or nothing could be reached; throws nothing
     */
    public static function find(string $pincode): ?array
    {
        return self::resolve($pincode)['found'];
    }

    /**
     * The lookup, and whether it was a real answer.
     *
     * `reachable` is false only when neither source could be asked. A null
     * `found` with `reachable` true means both said no: the code is wrong.
     *
     * @return array{found: array|null, reachable: bool}
     */
    public static function resolve(string $pincode): array
    {
        if (preg_match('/^[1-9][0-9]{5}$/', $pincode) !== 1) {
            return ['found' => null, 'reachable' => true];
        }

        // A PIN code's post offices change on the scale of years. A miss is
        // remembered for a day, so a typo is not re-asked on every keystroke
        // but a real code added upstream is picked up.
        $cached = Cache::get('pincode:'.$pincode);

        if ($cached !== null) {
            return ['found' => $cached === false ? null : $cached, 'reachable' => true];
        }

        $reachable = false;

        try {
            $found = self::fromIndiaPost($pincode);
            $reachable = true;
        } catch (GeocoderUnavailable) {
            $found = null;
        }

        if ($found === null) {
            try {
                $found = Geocoder::postcode($pincode);
                $reachable = true;
            } catch (GeocoderUnavailable) {
                // Both down: say so rather than "no such code".
            }
        }

        if ($found === null) {
            if ($reachable) {
                Cache::put('pincode:'.$pincode, false, now()->addDay());
            }

            return ['found' => null, 'reachable' => $reachable];
        }

        Cache::put('pincode:'.$pincode, $found, now()->addDays(30));

        return ['found' => $found, 'reachable' => true];
    }

    /**
     * @return array{pincode: string, state: ?string, state_id: ?int, district: ?string, places: array<int, array{name: string, block: ?string}>}|null
     *
     * @throws GeocoderUnavailable
     */
    private static function fromIndiaPost(string $pincode): ?array
    {
        try {
            $response = Http::timeout(8)->acceptJson()->get(self::SOURCE.$pincode);
        } catch (Throwable $e) {
            throw new GeocoderUnavailable('The PIN code directory could not be reached.', previous: $e);
        }

        if (! $response->successful()) {
            throw new GeocoderUnavailable('The PIN code directory answered '.$response->status().'.');
        }

        $offices = collect($response->json('0.PostOffice') ?? []);

        if ($response->json('0.Status') !== 'Success' || $offices->isEmpty()) {
            return null;
        }

        $stateName = (string) $offices->first()['State'];
        $state = State::query()->whereRaw('lower(name) = ?', [mb_strtolower($stateName)])->first();

        return [
            'pincode' => $pincode,
            'state' => $state?->name ?? $stateName,
            'state_id' => $state?->getKey(),
            'district' => $offices->first()['District'] ?? null,
            'places' => $offices
                ->map(fn (array $office): array => [
                    'name' => (string) $office['Name'],
                    'block' => filled($office['Block'] ?? null) && ($office['Block'] ?? null) !== 'NA' ? $office['Block'] : null,
                ])
                ->unique('name')
                ->values()
                ->all(),
        ];
    }
}
