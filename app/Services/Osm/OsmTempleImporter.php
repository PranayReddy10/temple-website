<?php

namespace App\Services\Osm;

use App\Enums\TempleStatus;
use App\Enums\VerificationStatus;
use App\Models\Deity;
use App\Models\District;
use App\Models\State;
use App\Models\Temple;
use Illuminate\Support\Str;

/**
 * Turns OpenStreetMap's Hindu places of worship into temple records, without
 * creating a temple twice and without touching an editor's work.
 *
 * Every OSM element gets one outcome:
 *
 * - unnamed:   mapped without a name. Nothing a devotee could search for, so
 *              it is left out.
 * - duplicate: the same temple mapped twice (a point and a building outline,
 *              say): same name within 150 m of one already seen this run.
 * - known:     an earlier run already imported it (matched on osm_ref).
 * - matched:   a temple we already have, found by name near the same spot.
 *              It is linked to its OSM element; empty fields are filled.
 * - new:       not in our database. Created as a community record.
 *
 * The honesty rules of TelanganaTempleSeeder apply. Everything imported is
 * COMMUNITY level with "OpenStreetMap contributors" as its source, which is
 * also the credit the ODbL licence requires. A temple an editor has verified
 * only ever gains its OSM references; its wording is never changed.
 */
class OsmTempleImporter
{
    public const SOURCE_NAME = 'OpenStreetMap contributors';

    /** Words that say "temple" or "honoured" rather than which temple. */
    protected const FILLER = [
        'sri', 'shri', 'sree', 'shree', 'swamy', 'swami', 'swamivari', 'vari', 'gari',
        'temple', 'temples', 'devalayam', 'devalaya', 'devasthanam', 'devastanam', 'alayam', 'aalayam',
        'gudi', 'mandir', 'mandiram', 'kovil', 'kshetram', 'mandapam', 'and', 'the', 'of', 'at',
    ];

    /**
     * Deity guessed from the temple's name, first match wins. Order matters:
     * "Lakshmi Narasimha" is Narasimha, not Lakshmi; "Rajarajeshwari" is
     * the goddess, not Shiva.
     *
     * @var array<string, string>
     */
    protected const DEITY_WORDS = [
        'narasimha' => 'narasimha|narasimhaswamy|narsimha|narsimhaswamy|narasinga|nrusimha|nrisimha|yadagiri',
        'venkateswara' => 'venkateswara|venkateshwara|venkateswaraswamy|venkateshwaraswamy|venkanna|balaji|srinivasa|tirupati|tirumala',
        'hanuman' => 'hanuman|hanumanth|hanumantha|hanumandla|anjaneya|anjaneyaswamy|anjanna|maruthi|maruti|bajrang|bajrangbali',
        'ganesha' => 'ganesh|ganesha|ganapati|ganapathi|vinayaka|vinayak|vighneshwara|vigneshwara|siddhivinayak',
        'murugan' => 'murugan|subramanya|subramanyaswamy|subrahmanya|subramanyeswara|kartikeya|skanda|shanmukha',
        'ayyappa' => 'ayyappa|ayyappan|ayyappaswamy|sabari',
        'saraswati' => 'saraswati|saraswathi|sarswati|sharada|sarada',
        'kali' => 'kali|mahankali|mahakali|bhadrakali|kalika',
        'devi' => 'devi|durga|amma|ammavaru|ammavari|matha|mata|pochamma|pochammathalli|yellamma|ellamma|mysamma|maisamma|peddamma|bhavani|renuka|renukamba|parvati|kanaka|mutyalamma|nallapochamma|jogulamba|eshwari|eswari|ishwari|meenakshi|kamakshi|annapurna|balamma|gangamma|kanakadurga',
        'lakshmi' => 'lakshmi|laxmi|mahalakshmi|mahalaxmi',
        'shiva' => 'shiva|siva|shiv|sivalayam|shivalayam|mahadev|mahadeva|shankar|shankara|mallikarjuna|mallanna|someshwara|someswara|rameshwara|rameswara|ramalingeswara|ramalingeshwara|rajarajeshwara|rajarajeswara|umamaheshwara|umamaheswara|kashi|vishwanatha|viswanatha|kedareshwara|neelakanteshwara|bhimeshwara|eshwara|eswara|iswara|ishwara|lingeswara|lingeshwara|shambhu|shambho|trikuteshwara|rudreshwara|rudra|mahalingeswara',
        'krishna' => 'krishna|gopala|venugopala|venugopal|gopalaswamy|radha|radhakrishna|iskcon|govinda',
        'rama' => 'rama|ram|ramalayam|ramalayamu|sitarama|seetharama|seetaramachandra|sitaramachandra|kodandarama|ramachandra|ramaswamy|ramji|rammandir',
        'vishnu' => 'vishnu|narayana|ranganatha|ranganathaswamy|chennakesava|chennakeshava|keshava|kesava|jagannath|jagannatha|varaha|vittala|vithoba|vitthal|panduranga|dattatreya',
        'surya' => 'surya|suryanarayana|suryadeva',
    ];

    /** @var array<string, ?int> */
    protected array $deityIds = [];

    /**
     * @param  list<array{type: string, id: int, lat: float, lon: float, tags: array<string, string>}>  $elements
     * @param  list<array{name: string, lat: float, lon: float}>  $places  villages and towns in the district, to name the temple's locality
     * @return list<array{osm_ref: string, name: ?string, locality: ?string, lat: float, lon: float, outcome: string, temple_id: ?int, temple_name: ?string, deity: ?string}>
     */
    public function process(State $state, District $district, array $elements, array $places, bool $write, TempleStatus $status = TempleStatus::Published): array
    {
        $report = [];
        $seen = [];

        // Buildings and compounds before bare points: an outline mapped with
        // its tags is usually the better-described copy of the same temple.
        usort($elements, fn (array $a, array $b): int => [$a['type'] === 'node', -count($a['tags'])] <=> [$b['type'] === 'node', -count($b['tags'])]);

        foreach ($elements as $element) {
            $ref = $element['type'].'/'.$element['id'];
            $name = $this->name($element['tags']);
            $locality = $this->locality($element, $places);

            $row = [
                'osm_ref' => $ref,
                'name' => $name,
                'locality' => $locality,
                'lat' => $element['lat'],
                'lon' => $element['lon'],
                'outcome' => 'new',
                'temple_id' => null,
                'temple_name' => null,
                'deity' => $name !== null ? $this->deitySlug($name, $element['tags']) : null,
            ];

            if ($name === null) {
                $report[] = ['outcome' => 'unnamed'] + $row;

                continue;
            }

            $key = $this->key($name);

            foreach ($seen as [$seenKey, $lat, $lon]) {
                if ($seenKey === $key && $this->metres($lat, $lon, $element['lat'], $element['lon']) <= 150) {
                    $report[] = ['outcome' => 'duplicate'] + $row;

                    continue 2;
                }
            }

            $seen[] = [$key, $element['lat'], $element['lon']];

            $temple = Temple::withTrashed()->where('osm_ref', $ref)->first();
            $outcome = 'known';

            if ($temple === null) {
                $temple = $this->findExisting($district, $name, $element['lat'], $element['lon']);
                $outcome = $temple !== null ? 'matched' : 'new';
            }

            if ($write) {
                $temple = $temple === null
                    ? $this->create($state, $district, $element, $name, $locality, $status)
                    : $this->fill($temple, $element, $name, $locality);
            }

            $report[] = [
                'outcome' => $outcome,
                'temple_id' => $temple?->id,
                'temple_name' => $temple?->name,
            ] + $row;
        }

        return $report;
    }

    /** The district record for a canonical name, made the same way TelanganaTempleSeeder makes it. */
    public function district(State $state, string $name): District
    {
        return District::firstOrCreate(
            ['state_id' => $state->id, 'slug' => Str::slug($name)],
            ['name' => $name],
        );
    }

    /**
     * A temple already in the district that is this one: a close name within
     * 300 m, or a near-identical name within 3 km (the hand-entered
     * coordinates of the first Telangana set are approximate).
     */
    protected function findExisting(District $district, string $name, float $lat, float $lon): ?Temple
    {
        $tokens = $this->tokens($name);

        if ($tokens === []) {
            return null;
        }

        $box = 0.03; // about 3 km

        $candidates = Temple::withTrashed()
            ->with('aliases')
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->whereBetween('latitude', [$lat - $box, $lat + $box])->whereBetween('longitude', [$lon - $box, $lon + $box]))
                ->orWhere(fn ($q) => $q->where('district_id', $district->id)->whereNull('latitude')))
            ->get();

        $best = null;
        $bestScore = 0.0;

        foreach ($candidates as $temple) {
            $metres = $temple->latitude !== null
                ? $this->metres($lat, $lon, (float) $temple->latitude, (float) $temple->longitude)
                : 0.0;

            foreach ([$temple->name, ...$temple->aliases->pluck('name')] as $candidateName) {
                [$overlap, $jaccard] = $this->similarity($tokens, $this->tokens((string) $candidateName));

                $isMatch = ($metres <= 300 && $overlap >= 0.6) || ($metres <= 3000 && $jaccard >= 0.5);

                if ($isMatch && $jaccard + $overlap > $bestScore) {
                    $best = $temple;
                    $bestScore = $jaccard + $overlap;
                }
            }
        }

        return $best;
    }

    /** @param array{type: string, id: int, lat: float, lon: float, tags: array<string, string>} $element */
    protected function create(State $state, District $district, array $element, string $name, ?string $locality, TempleStatus $status): Temple
    {
        // forceCreate: the OSM references are not mass-assignable from forms.
        $temple = Temple::forceCreate($this->attributes($element, $name, $locality) + $this->references($element) + [
            'name' => $name,
            'slug' => $this->uniqueSlug($name, $locality, $district->name, $element),
            'deity_id' => $this->deityId($this->deitySlug($name, $element['tags'])),
            'state_id' => $state->id,
            'district_id' => $district->id,
            'latitude' => $element['lat'],
            'longitude' => $element['lon'],
            'status' => $status,
            'is_featured' => false,
            'verification_status' => VerificationStatus::Community,
            'last_verified_at' => null,
        ]);

        $this->addAliases($temple, $element['tags'], $name);

        return $temple;
    }

    /** @param array{type: string, id: int, lat: float, lon: float, tags: array<string, string>} $element */
    protected function fill(Temple $temple, array $element, string $name, ?string $locality): Temple
    {
        // References are metadata, never an editor's wording: link them even
        // on a verified temple, but only where the slot is empty.
        $fill = array_filter(
            $this->references($element),
            fn ($value, string $key): bool => $value !== null && blank($temple->getAttribute($key))
                && ($key !== 'osm_ref' || ! Temple::withTrashed()->where('osm_ref', $value)->exists()),
            ARRAY_FILTER_USE_BOTH,
        );

        if (! $this->editorOwns($temple)) {
            $attributes = $this->attributes($element, $name, $locality) + [
                'deity_id' => $this->deityId($this->deitySlug($name, $element['tags'])),
                'latitude' => $element['lat'],
                'longitude' => $element['lon'],
            ];

            // A source already recorded (Wikipedia, say) is the better one to keep.
            unset($attributes['source_name'], $attributes['source_url']);

            $fill += array_filter(
                $attributes,
                fn ($value, string $key): bool => $value !== null && blank($temple->getAttribute($key)),
                ARRAY_FILTER_USE_BOTH,
            );

            $this->addAliases($temple, $element['tags'], $temple->name);
        }

        $temple->forceFill($fill)->save();

        return $temple;
    }

    /**
     * @param  array{type: string, id: int, tags: array<string, string>}  $element
     * @return array<string, mixed>
     */
    protected function attributes(array $element, string $name, ?string $locality): array
    {
        $tags = $element['tags'];

        $website = $tags['website'] ?? $tags['contact:website'] ?? $tags['url'] ?? null;
        $phone = $tags['phone'] ?? $tags['contact:phone'] ?? null;
        $postcode = preg_match('/^\d{6}$/', str_replace(' ', '', $tags['addr:postcode'] ?? '')) === 1
            ? str_replace(' ', '', $tags['addr:postcode'])
            : null;

        $address = $tags['addr:full'] ?? collect([
            trim(($tags['addr:housenumber'] ?? '').' '.($tags['addr:street'] ?? '')),
            $tags['addr:suburb'] ?? $tags['addr:place'] ?? null,
            $tags['addr:city'] ?? $tags['addr:village'] ?? $tags['addr:town'] ?? null,
        ])->filter()->unique()->implode(', ');

        return [
            'city' => $tags['addr:city'] ?? $tags['addr:village'] ?? $tags['addr:town'] ?? $tags['addr:hamlet'] ?? $locality,
            'address' => filled($address) ? $address : null,
            'pincode' => $postcode,
            'short_description' => filled($tags['description'] ?? null) ? Str::limit($tags['description'], 500) : null,
            'official_website' => $website !== null && Str::startsWith($website, ['http://', 'https://']) ? Str::limit($website, 250, '') : null,
            'contact_phone' => $phone !== null ? Str::limit(explode(';', $phone)[0], 40, '') : null,
            'source_name' => self::SOURCE_NAME,
            'source_url' => 'https://www.openstreetmap.org/'.$element['type'].'/'.$element['id'],
        ];
    }

    /**
     * @param  array{type: string, id: int, tags: array<string, string>}  $element
     * @return array{osm_ref: string, wikidata_id: ?string, commons_image: ?string, wikipedia_url: ?string}
     */
    protected function references(array $element): array
    {
        $wikidata = $element['tags']['wikidata'] ?? null;

        return [
            'osm_ref' => $element['type'].'/'.$element['id'],
            'wikidata_id' => $wikidata !== null && preg_match('/^Q\d+$/', $wikidata) === 1 ? $wikidata : null,
            'commons_image' => $this->commonsFile($element['tags']),
            'wikipedia_url' => $this->wikipediaUrl($element['tags']),
        ];
    }

    /**
     * The article OpenStreetMap links: its wikipedia tag is "en:Ramappa
     * Temple" (language, then title), or wikipedia:te for another language.
     *
     * @param  array<string, string>  $tags
     */
    public function wikipediaUrl(array $tags): ?string
    {
        $tag = trim($tags['wikipedia'] ?? '');
        if ($tag === '') {
            foreach (['en', 'te', 'hi'] as $lang) {
                if (filled($tags['wikipedia:'.$lang] ?? null)) {
                    $tag = $lang.':'.trim($tags['wikipedia:'.$lang]);
                    break;
                }
            }
        }
        if (preg_match('~^https?://([a-z]{2,3})\.(?:m\.)?wikipedia\.org/wiki/(.+)$~i', $tag, $m) === 1) {
            $tag = $m[1].':'.rawurldecode($m[2]);
        }
        if (preg_match('/^([a-z]{2,3}):(.+)$/', $tag, $m) !== 1) {
            return null;
        }

        return Str::limit('https://'.$m[1].'.wikipedia.org/wiki/'.str_replace(' ', '_', trim($m[2])), 500, '');
    }

    /**
     * Whether two names are the same temple's: the distinctive words of the
     * shorter are nearly all in the longer ("Ramappa Temple" and "Ramappa
     * Rudreswara Temple"). Used to accept a Wikipedia article found nearby.
     */
    public function sameName(string $a, string $b): bool
    {
        [$overlap] = $this->similarity($this->tokens($a), $this->tokens($b));

        return $overlap >= 0.6;
    }

    /**
     * A Commons file named by the element's wikimedia_commons or image tag,
     * as "File:Name.jpg". Anything hosted elsewhere is ignored: its licence
     * cannot be checked.
     *
     * @param  array<string, string>  $tags
     */
    public function commonsFile(array $tags): ?string
    {
        $commons = trim($tags['wikimedia_commons'] ?? '');

        if (Str::startsWith($commons, 'File:')) {
            return Str::limit($commons, 250, '');
        }

        $image = trim($tags['image'] ?? '');

        if (preg_match('~commons\.wikimedia\.org/wiki/(File:[^?#]+)~i', $image, $m) === 1) {
            return Str::limit(str_replace('_', ' ', rawurldecode($m[1])), 250, '');
        }

        if (preg_match('~upload\.wikimedia\.org/wikipedia/commons/(?:thumb/)?[0-9a-f]/[0-9a-f]{2}/([^/?#]+)~i', $image, $m) === 1) {
            return Str::limit('File:'.str_replace('_', ' ', rawurldecode($m[1])), 250, '');
        }

        return null;
    }

    /** @param array<string, string> $tags */
    protected function addAliases(Temple $temple, array $tags, string $name): void
    {
        $candidates = [];

        foreach (['name', 'name:en', 'name:te', 'name:hi', 'alt_name', 'old_name', 'official_name'] as $key) {
            foreach (explode(';', $tags[$key] ?? '') as $alias) {
                $alias = trim($alias);

                if ($alias !== '' && mb_strtolower($alias) !== mb_strtolower($name)) {
                    $candidates[$alias] = match (true) {
                        $key === 'name:hi' => 'hi',
                        preg_match('/\p{Telugu}/u', $alias) === 1 => 'te',
                        preg_match('/\p{Devanagari}/u', $alias) === 1 => 'hi',
                        default => 'en',
                    };
                }
            }
        }

        foreach ($candidates as $alias => $locale) {
            $temple->aliases()->firstOrCreate(['name' => Str::limit($alias, 250, '')], ['locale' => $locale]);
        }
    }

    /**
     * The name to show: the English name where a mapper gave one, otherwise
     * the name as mapped (often Telugu script, which is still right).
     *
     * @param  array<string, string>  $tags
     */
    public function name(array $tags): ?string
    {
        $name = trim($tags['name:en'] ?? '') ?: trim($tags['name'] ?? '');

        // "Temple" or "Hindu temple" on its own names nothing.
        if ($name === '' || $this->tokens($name) === [] || in_array(Str::lower($name), ['hindu temple', 'hindu mandir', 'place of worship'], true)) {
            return null;
        }

        return Str::limit(preg_replace('/\s+/u', ' ', $name) ?? $name, 250, '');
    }

    /**
     * The nearest village or town within 5 km, when the element carries no
     * address of its own (most do not).
     *
     * @param  array{lat: float, lon: float}  $element
     * @param  list<array{name: string, lat: float, lon: float}>  $places
     */
    protected function locality(array $element, array $places): ?string
    {
        $best = null;
        $bestMetres = 5000.0;

        foreach ($places as $place) {
            // Cheap reject before the trigonometry: 0.05° is about 5.5 km.
            if (abs($place['lat'] - $element['lat']) > 0.05 || abs($place['lon'] - $element['lon']) > 0.05) {
                continue;
            }

            $metres = $this->metres($element['lat'], $element['lon'], $place['lat'], $place['lon']);

            if ($metres < $bestMetres) {
                $best = $place['name'];
                $bestMetres = $metres;
            }
        }

        return $best;
    }

    /** @param array<string, string> $tags */
    public function deitySlug(string $name, array $tags = []): ?string
    {
        $haystack = ' '.implode(' ', $this->words(($tags['deity'] ?? '').' '.$name)).' ';

        foreach (self::DEITY_WORDS as $slug => $words) {
            if (preg_match('/ ('.$words.') /', $haystack) === 1) {
                return $slug;
            }
        }

        return null;
    }

    protected function deityId(?string $slug): ?int
    {
        if ($slug === null) {
            return null;
        }

        return $this->deityIds[$slug] ??= Deity::where('slug', $slug)->value('id');
    }

    /**
     * Slugs are unique across India, and "Hanuman Temple" exists in every
     * village, so the locality and district are added until one is free.
     *
     * @param  array{type: string, id: int}  $element
     */
    protected function uniqueSlug(string $name, ?string $locality, string $district, array $element): string
    {
        $base = Str::slug($name);

        // A name in Telugu script alone slugs to nothing.
        if ($base === '') {
            $base = 'temple-'.Str::slug($locality ?? $district);
        }

        $options = array_values(array_unique(array_filter([
            $base,
            $locality !== null ? Str::slug($base.' '.$locality) : null,
            Str::slug($base.' '.($locality ?? '').' '.$district),
        ])));

        foreach ($options as $slug) {
            if (! Temple::withTrashed()->where('slug', $slug)->exists()) {
                return $slug;
            }
        }

        return end($options).'-'.$element['id'];
    }

    protected function editorOwns(Temple $temple): bool
    {
        return $temple->trashed()
            || in_array($temple->verification_status, [VerificationStatus::Verified, VerificationStatus::Official], true)
            || $temple->last_verified_at !== null;
    }

    /** @return list<string> */
    protected function words(string $text): array
    {
        $text = Str::lower(Str::ascii($text));

        return array_values(array_filter(preg_split('/[^a-z0-9]+/', $text) ?: []));
    }

    /** @return list<string> the distinctive words of a name */
    protected function tokens(string $name): array
    {
        // A Telugu-only name has no Latin words; compare it as a whole.
        if (preg_match('/[a-z]/i', Str::ascii($name)) !== 1) {
            return [mb_strtolower(trim($name))];
        }

        return array_values(array_unique(array_filter(
            $this->words($name),
            fn (string $w): bool => strlen($w) > 1 && ! in_array($w, self::FILLER, true),
        )));
    }

    protected function key(string $name): string
    {
        $tokens = $this->tokens($name);
        sort($tokens);

        return implode(' ', $tokens);
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     * @return array{0: float, 1: float} overlap coefficient, Jaccard index
     */
    protected function similarity(array $a, array $b): array
    {
        if ($a === [] || $b === []) {
            return [0.0, 0.0];
        }

        $common = count(array_intersect($a, $b));

        return [
            $common / min(count($a), count($b)),
            $common / count(array_unique([...$a, ...$b])),
        ];
    }

    protected function metres(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $rad = M_PI / 180;
        $dLat = ($lat2 - $lat1) * $rad;
        $dLon = ($lon2 - $lon1) * $rad;
        $h = sin($dLat / 2) ** 2 + cos($lat1 * $rad) * cos($lat2 * $rad) * sin($dLon / 2) ** 2;

        return 6_371_000 * 2 * asin(min(1, sqrt($h)));
    }
}
