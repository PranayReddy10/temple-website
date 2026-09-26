<?php

namespace App\Services\Osm;

use Illuminate\Support\Str;

/**
 * The 33 districts of Telangana under the names this site uses, and the other
 * spellings OpenStreetMap is known to carry for them.
 *
 * OSM names districts as its mappers wrote them: "Ranga Reddy" for
 * Rangareddy, "Komaram Bheem" for Kumuram Bheem, and in places still the
 * pre-2021 "Warangal Urban" and "Warangal Rural". Without this list the
 * import would open a second district beside the one TelanganaTempleSeeder
 * already made, and split its temples between the two.
 */
final class TelanganaDistricts
{
    /** @var array<string, list<string>> canonical name => other spellings */
    public const NAMES = [
        'Adilabad' => [],
        'Bhadradri Kothagudem' => ['Kothagudem', 'Bhadradri'],
        'Hanumakonda' => ['Hanamkonda', 'Hanamakonda', 'Warangal Urban'],
        'Hyderabad' => [],
        'Jagtial' => ['Jagitial', 'Jagityal'],
        'Jangaon' => ['Jangoan', 'Janagama'],
        'Jayashankar Bhupalpally' => ['Jayashankar Bhupalapally', 'Jayashankar', 'Bhupalpally'],
        'Jogulamba Gadwal' => ['Gadwal', 'Jogulamba'],
        'Kamareddy' => [],
        'Karimnagar' => [],
        'Khammam' => [],
        'Kumuram Bheem Asifabad' => ['Komaram Bheem Asifabad', 'Komaram Bheem', 'Kumram Bheem Asifabad', 'Kumuram Bheem', 'Asifabad'],
        'Mahabubabad' => ['Mahbubabad'],
        'Mahabubnagar' => ['Mahbubnagar', 'Mahaboobnagar'],
        'Mancherial' => [],
        'Medak' => [],
        'Medchal–Malkajgiri' => ['Medchal-Malkajgiri', 'Medchal Malkajgiri', 'Medchal'],
        'Mulugu' => [],
        'Nagarkurnool' => ['Nagar Kurnool'],
        'Nalgonda' => [],
        'Narayanpet' => [],
        'Nirmal' => [],
        'Nizamabad' => [],
        'Peddapalli' => ['Peddapalle'],
        'Rajanna Sircilla' => ['Rajanna Siricilla', 'Sircilla'],
        'Rangareddy' => ['Ranga Reddy', 'Ranga Reddi'],
        'Sangareddy' => ['Sangareddi'],
        'Siddipet' => [],
        'Suryapet' => [],
        'Vikarabad' => [],
        'Wanaparthy' => ['Wanaparthi'],
        'Warangal' => ['Warangal Rural'],
        'Yadadri Bhuvanagiri' => ['Yadadri', 'Bhuvanagiri', 'Yadadri Bhongir'],
    ];

    /** The site's name for an OSM district name, or null if it is not one of the 33. */
    public static function canonical(string $osmName): ?string
    {
        $key = self::key($osmName);

        foreach (self::NAMES as $name => $aliases) {
            foreach ([$name, ...$aliases] as $candidate) {
                if (self::key($candidate) === $key) {
                    return $name;
                }
            }
        }

        return null;
    }

    private static function key(string $name): string
    {
        $name = preg_replace('/\bdistrict\b/i', '', $name) ?? $name;

        return str_replace('-', '', Str::slug(str_replace('–', ' ', $name)));
    }
}
