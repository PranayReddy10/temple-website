<?php

namespace App\Support\TempleImport;

use App\Models\Temple;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Finds the temple a researched record describes, if the site already has
 * it, so an import never lists the same temple twice.
 *
 * The same temple turns up under many names: "Kanipakam Temple", "Sri
 * Varasiddhi Vinayaka Swamy Temple, Kanipakam", "కాణిపాకం". So a record is
 * the same temple as a listed one when, within the state:
 *
 * - the slug is the same; or
 * - any of its names (name or other names) reduces to the same distinctive
 *   words as any of the listed temple's names; or
 * - its pin is within SAME_PLACE_METRES of a listed one; or
 * - its pin is within NEARBY_METRES of one sharing a distinctive word of a
 *   name ("Srikalahasti" next to "Sri Kalahasteeswara Swamy Temple").
 *
 * Deleted temples are matched too: an editor who deleted one meant it.
 */
final class TempleMatcher
{
    public const SAME_PLACE_METRES = 60;

    public const NEARBY_METRES = 400;

    /** Two temples sharing another name are one only within this distance. */
    public const SAME_NAME_KM = 30;

    /** Words every temple name has, which tell no two temples apart. */
    private const FILLER = [
        'sri', 'shri', 'sree', 'shree', 'temple', 'temples', 'swamy', 'swami', 'swamivari', 'devasthanam', 'devalayam',
        'alayam', 'mandir', 'mandiram', 'gudi', 'kovil', 'koil', 'the', 'of', 'at', 'and', 'ammavari', 'ammavaru',
        'vari', 'devi', 'kshetram', 'kshetra', 'mutt', 'math',
    ];

    /** @var Collection<int, array{temple: Temple, nameKey: string, town: ?string, keys: array<int, string>, words: array<int, string>}> */
    private Collection $listed;

    public function __construct(private readonly ?int $stateId)
    {
        $this->listed = Temple::withTrashed()
            ->when($stateId !== null, fn ($q) => $q->where('state_id', $stateId))
            ->with('aliases')
            ->get()
            ->map(fn (Temple $t) => $this->describe($t));
    }

    /**
     * @param  array<string, mixed>  $row  a researched temple: name, aliases, lat, lng
     */
    public function find(array $row): ?Temple
    {
        $slug = Str::slug((string) $row['name']);
        if ($hit = $this->listed->first(fn ($l) => $l['temple']->slug === $slug)) {
            return $hit['temple'];
        }

        $lat = isset($row['lat']) ? (float) $row['lat'] : null;
        $lng = isset($row['lng']) ? (float) $row['lng'] : null;
        $nameKey = self::key((string) $row['name']);
        $nameWords = self::words((string) $row['name']);
        $names = [(string) $row['name'], ...array_keys((array) ($row['aliases'] ?? []))];
        // Other names are looser ("Narasimha Temple" fits many), so they
        // only count with two distinctive words.
        $aliasKeys = array_values(array_filter(array_map(self::key(...), $names), fn (string $k) => substr_count($k, ' ') >= 1));
        $hit = $this->listed->first(fn ($l) => ($nameKey !== '' && $nameKey === $l['nameKey'])
            || ($this->near($lat, $lng, $l['temple'], self::SAME_NAME_KM * 1000) && (
                // "Kanipakam Temple", listed as another name of the temple.
                ($nameKey !== '' && in_array($nameKey, $l['keys'], true))
                || array_intersect($aliasKeys, $l['keys']) !== []
                // "Kanipakam Vinayaka Temple": a shorter name of the listed
                // temple, naming its town.
                || (count($nameWords) >= 2 && $l['town'] !== null && in_array($l['town'], $nameWords, true)
                    && array_diff($nameWords, $l['words']) === [])
            )));
        if ($hit) {
            return $hit['temple'];
        }

        if ($lat === null || $lng === null) {
            return null;
        }

        $words = array_values(array_unique(array_merge(...array_map(self::words(...), $names))));

        return $this->listed
            ->filter(fn ($l) => $l['temple']->latitude !== null && $l['temple']->longitude !== null)
            ->map(fn ($l) => $l + ['metres' => self::metres($lat, $lng, (float) $l['temple']->latitude, (float) $l['temple']->longitude)])
            ->filter(fn ($l) => $l['metres'] <= self::SAME_PLACE_METRES
                || ($l['metres'] <= self::NEARBY_METRES && self::shareWord($words, $l['words'])))
            ->sortBy('metres')
            ->first()['temple'] ?? null;
    }

    /** Count a temple just created, so a later row in the same run matches it. */
    public function remember(Temple $temple): void
    {
        $temple->load('aliases');
        $this->listed = $this->listed
            ->reject(fn ($l) => $l['temple']->is($temple))
            ->push($this->describe($temple));
    }

    /** @return array{temple: Temple, nameKey: string, town: ?string, keys: array<int, string>, words: array<int, string>} */
    private function describe(Temple $temple): array
    {
        $names = [$temple->name, ...$temple->aliases->pluck('name')->all()];

        return [
            'temple' => $temple,
            'nameKey' => self::key($temple->name),
            'town' => $temple->city ? (self::words($temple->city)[0] ?? null) : null,
            'keys' => array_values(array_filter(array_map(self::key(...), $names))),
            'words' => array_values(array_unique(array_merge(...array_map(self::words(...), [...$names, (string) $temple->city])))),
        ];
    }

    /** A name reduced to its distinctive words, in order: "sri kanaka durga temple, vijayawada" → "durga kanaka vijayawada". */
    public static function key(string $name): string
    {
        $words = self::words($name);
        sort($words);

        return implode(' ', $words);
    }

    /** @return array<int, string> */
    public static function words(string $name): array
    {
        // Latin names fold accents ("Śrī" is "sri"); Telugu and other scripts stay as written.
        $plain = mb_strtolower(preg_match('/^[\p{Latin}\p{N}\p{P}\p{Zs}]*$/u', $name) ? Str::ascii($name) : $name);
        $words = preg_split('/[^\p{L}\p{M}\p{N}]+/u', $plain, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter(
            $words,
            fn (string $w): bool => mb_strlen($w) > 2 && ! in_array($w, self::FILLER, true),
        )));
    }

    /** Unpinned temples cannot be told apart by place, so count them as near. */
    private function near(?float $lat, ?float $lng, Temple $temple, float $metres): bool
    {
        if ($lat === null || $lng === null || $temple->latitude === null || $temple->longitude === null) {
            return true;
        }

        return self::metres($lat, $lng, (float) $temple->latitude, (float) $temple->longitude) <= $metres;
    }

    /**
     * Whether two names share a distinctive word, allowing the joins Telugu
     * names take in English: "kalahasti" in "srikalahasti" or "kalahasteeswara".
     *
     * @param  array<int, string>  $a
     * @param  array<int, string>  $b
     */
    private static function shareWord(array $a, array $b): bool
    {
        foreach ($a as $x) {
            foreach ($b as $y) {
                if ($x === $y || (mb_strlen($x) >= 6 && mb_strlen($y) >= 6 && (str_contains($x, mb_substr($y, 0, 6)) || str_contains($y, mb_substr($x, 0, 6))))) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function metres(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * 6371000 * asin(min(1, sqrt($a)));
    }
}
