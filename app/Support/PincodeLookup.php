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
 */
final class PincodeLookup
{
    public const SOURCE = 'https://api.postalpincode.in/pincode/';

    /**
     * @return array{pincode: string, state: ?string, state_id: ?int, district: ?string, places: array<int, array{name: string, block: ?string}>}|null
     *         null when the code does not exist; throws nothing
     */
    public static function find(string $pincode): ?array
    {
        if (preg_match('/^[1-9][0-9]{5}$/', $pincode) !== 1) {
            return null;
        }

        // A PIN code's post offices change on the scale of years. A miss is
        // remembered for a day, so a typo is not re-asked on every keystroke
        // but a real code added upstream is picked up.
        $cached = Cache::get('pincode:'.$pincode);

        if ($cached !== null) {
            return $cached === false ? null : $cached;
        }

        try {
            $response = Http::timeout(8)->acceptJson()->get(self::SOURCE.$pincode);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $offices = collect($response->json('0.PostOffice') ?? []);

        if ($response->json('0.Status') !== 'Success' || $offices->isEmpty()) {
            Cache::put('pincode:'.$pincode, false, now()->addDay());

            return null;
        }

        $stateName = (string) $offices->first()['State'];
        $state = State::query()->whereRaw('lower(name) = ?', [mb_strtolower($stateName)])->first();

        $result = [
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

        Cache::put('pincode:'.$pincode, $result, now()->addDays(30));

        return $result;
    }
}
