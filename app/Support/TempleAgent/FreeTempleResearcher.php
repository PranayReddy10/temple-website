<?php

namespace App\Support\TempleAgent;

use App\Models\State;
use App\Support\OfficialSite\OfficialSiteImport;
use App\Support\OfficialSite\OfficialSiteReader;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * The research agent at no cost: only free, open sources, no API key.
 *
 * - Wikidata finds the state's temples, most notable first (by how many
 *   Wikipedia languages write about them), and gives the structured facts:
 *   map location, official website, when built, style, deity, Telugu name,
 *   World Heritage status.
 * - Wikipedia gives the summary, history and significance, as written there.
 * - The temple's own website, read by OfficialSiteReader, gives timings,
 *   sevas, phone and email.
 * - OpenStreetMap, through the Geocoder, gives the address, PIN code and
 *   district at the map location.
 *
 * Every fact comes from one of these and is listed in the record's sources;
 * nothing is guessed. What none of them has stays empty.
 */
final class FreeTempleResearcher implements TempleResearcher
{
    public const WIKIDATA_SPARQL = 'https://query.wikidata.org/sparql';

    public const WIKIDATA_ENTITY = 'https://www.wikidata.org/wiki/Special:EntityData/';

    public const WIKIDATA_API = 'https://www.wikidata.org/w/api.php';

    public const WIKIPEDIA_API = 'https://en.wikipedia.org/w/api.php';

    /** Words in a deity's name that point to one of the site's deities. */
    private const DEITY_WORDS = [
        'venkateswara' => ['venkateswara', 'venkateshwara', 'balaji', 'srinivasa', 'govindaraja'],
        'narasimha' => ['narasimha', 'nrusimha', 'nrisimha'],
        'hanuman' => ['hanuman', 'anjaneya', 'maruti'],
        'ganesha' => ['ganesha', 'ganesh', 'ganapati', 'vinayaka', 'vinayagar'],
        'murugan' => ['murugan', 'kartikeya', 'subrahmanya', 'subramanya', 'skanda'],
        'rama' => ['rama', 'ramachandra', 'kodandarama'],
        'krishna' => ['krishna', 'jagannath'],
        'lakshmi' => ['lakshmi', 'mahalakshmi'],
        'saraswati' => ['saraswati', 'sarasvati'],
        'kali' => ['kali', 'mahakali', 'bhadrakali'],
        'surya' => ['surya', 'suryanarayana'],
        'shiva' => ['shiva', 'siva', 'mahadeva', 'lingam', 'linga', 'eswara', 'eshwara', 'iswara', 'mallikarjuna', 'veerabhadra', 'rudra'],
        'vishnu' => ['vishnu', 'narayana', 'kesava', 'ranganatha', 'varaha', 'kurma', 'satyanarayana'],
        'devi' => ['devi', 'durga', 'parvati', 'amma', 'shakti', 'kamakshi', 'yellamma', 'mahankali', 'bhramaramba'],
    ];

    public function discover(State $state, array $listed, int $limit): array
    {
        $name = addslashes($state->name);
        $query = <<<SPARQL
SELECT ?item ?itemLabel ?title ?placeLabel ?coord ?links WHERE {
  ?state wdt:P31 wd:Q131541; rdfs:label "{$name}"@en.
  ?item wdt:P31/wdt:P279* wd:Q842402; wdt:P131+ ?state; wikibase:sitelinks ?links.
  OPTIONAL { ?item wdt:P131 ?place. }
  OPTIONAL { ?item wdt:P625 ?coord. }
  OPTIONAL { ?article schema:about ?item; schema:isPartOf <https://en.wikipedia.org/>; schema:name ?title. }
  SERVICE wikibase:label { bd:serviceParam wikibase:language "en". }
}
ORDER BY DESC(?links)
LIMIT 600
SPARQL;

        $rows = $this->json(Http::withHeaders($this->headers())->timeout(60)->acceptJson()
            ->get(self::WIKIDATA_SPARQL, ['query' => $query, 'format' => 'json']))['results']['bindings'] ?? [];

        $temples = [];
        foreach ($rows as $row) {
            $qid = basename((string) ($row['item']['value'] ?? ''));
            if ($qid === '' || isset($temples[$qid])) {
                continue;
            }
            $label = $row['title']['value'] ?? $row['itemLabel']['value'] ?? null;
            // An item with no English name is labelled with its Q-number.
            if ($label === null || preg_match('/^Q\d+$/', $label)) {
                continue;
            }
            [$lat, $lng] = self::point($row['coord']['value'] ?? null);
            $temples[$qid] = [
                'name' => $label,
                'city' => $row['placeLabel']['value'] ?? null,
                'district' => null,
                'why' => 'Written about in '.($row['links']['value'] ?? '?').' Wikipedia languages',
                'wikidata' => $qid,
                'lat' => $lat,
                'lng' => $lng,
            ];
        }

        // More than asked for: the command skips those already listed.
        return array_slice(array_values($temples), 0, max($limit * 4, $limit + count($listed)));
    }

    public function research(State $state, array $candidate): ?array
    {
        $qid = $candidate['wikidata'] ?? $this->findItem($candidate['name'], $state);
        $entity = $qid ? $this->entity($qid) : null;
        $title = $entity['sitelinks']['enwiki']['title'] ?? null;
        $sources = [];

        $row = [
            'name' => $candidate['name'],
            'deity' => null, 'famous' => false,
            'district' => $candidate['district'] ?? null, 'city' => $candidate['city'] ?? null,
            'address' => null, 'pincode' => null,
            'lat' => $candidate['lat'] ?? null, 'lng' => $candidate['lng'] ?? null,
            'categories' => [], 'aliases' => [],
            'summary' => null, 'history' => null, 'significance' => null,
            'architecture_style' => null, 'built_period' => null,
            'website' => null, 'phone' => null, 'email' => null,
            'rules' => ['dress_code' => null, 'photography' => null, 'mobile' => null, 'footwear' => null, 'entry' => null, 'queue' => null],
            'timings' => [], 'timings_source' => null, 'pujas' => [],
            'nearest' => ['railway' => null, 'airport' => null, 'bus' => null],
        ];

        if ($entity !== null) {
            $sources[] = ['name' => 'Wikidata', 'url' => 'https://www.wikidata.org/wiki/'.$qid];
            $this->fromWikidata($entity, $row);
        }
        if ($title !== null && ($text = $this->article($title))) {
            $sources[] = ['name' => 'Wikipedia', 'url' => 'https://en.wikipedia.org/wiki/'.str_replace(' ', '_', $title)];
            $this->fromWikipedia($text, $row);
        }
        if ($row['website'] !== null) {
            $this->fromOfficialSite($row, $sources);
        }
        if (is_numeric($row['lat']) && is_numeric($row['lng'])) {
            $this->fromMap($row, $sources);
        }

        if ($sources === [] || blank($row['district'])) {
            return null; // Nothing found, or not placed in a district: not worth a record.
        }
        $row['sources'] = $sources;

        return $row;
    }

    /** @param array<string, mixed> $entity */
    private function fromWikidata(array $entity, array &$row): void
    {
        $claims = $entity['claims'] ?? [];
        [$lat, $lng] = [null, null];
        if ($coord = self::value($claims, 'P625')) {
            [$lat, $lng] = [$coord['latitude'] ?? null, $coord['longitude'] ?? null];
        }
        $row['lat'] = $lat ?? $row['lat'];
        $row['lng'] = $lng ?? $row['lng'];
        $row['website'] = self::value($claims, 'P856');
        $row['pincode'] = self::value($claims, 'P281');
        if ($time = self::value($claims, 'P571')) {
            $row['built_period'] = self::period($time);
        }

        $refs = array_filter([
            'style' => self::value($claims, 'P149')['id'] ?? null,
            'deity' => self::value($claims, 'P825')['id'] ?? null,
            'place' => self::value($claims, 'P131')['id'] ?? null,
        ]);
        $labels = $this->labels(array_values($refs));
        $row['architecture_style'] = isset($refs['style']) ? ($labels[$refs['style']] ?? null) : null;
        $row['city'] ??= isset($refs['place']) ? ($labels[$refs['place']] ?? null) : null;
        $row['deity'] = self::deity(implode(' ', array_filter([
            isset($refs['deity']) ? ($labels[$refs['deity']] ?? null) : null,
            $row['name'],
        ])));

        foreach ($claims['P1435'] ?? [] as $heritage) {
            if (($heritage['mainsnak']['datavalue']['value']['id'] ?? null) === 'Q9259') {
                $row['categories'][] = 'unesco-world-heritage';
            }
        }

        if ($te = $entity['labels']['te']['value'] ?? null) {
            $row['aliases'][$te] = 'te';
        }
        if (($en = $entity['labels']['en']['value'] ?? null) && $en !== $row['name']) {
            $row['aliases'][$en] = 'en';
        }
        foreach (array_slice($entity['aliases']['en'] ?? [], 0, 4) as $alias) {
            $row['aliases'][$alias['value']] = 'en';
        }
    }

    private function fromWikipedia(string $text, array &$row): void
    {
        $sections = self::sections($text);
        $lead = $sections[''] ?? '';
        $row['summary'] = self::sentences($lead, 2, 300);

        foreach ($sections as $heading => $body) {
            if ($row['history'] === null && preg_match('/history|legend|origin/i', $heading)) {
                $row['history'] = self::sentences($body, 6, 1200);
            }
            if ($row['significance'] === null && preg_match('/festival|significance|worship|religious|ritual|celebration/i', $heading)) {
                $row['significance'] = self::sentences($body, 6, 1200);
            }
        }
        // The lead often carries the history when the article has no section for it.
        $row['history'] ??= self::sentences(implode(' ', array_slice(preg_split('/(?<=[.!?])\s+/', $lead) ?: [], 2)), 4, 900);
        $row['deity'] ??= self::deity($lead);
    }

    private function fromOfficialSite(array &$row, array &$sources): void
    {
        try {
            $site = OfficialSiteReader::read((string) $row['website']);
        } catch (Throwable) {
            return;
        }
        if (isset($site['error'])) {
            return;
        }
        $sources[] = ['name' => 'Official website', 'url' => (string) ($site['source_url'] ?? $row['website'])];
        $row['website'] = (string) ($site['source_url'] ?? $row['website']);
        $row['phone'] = $site['phones'][0] ?? null;
        $row['email'] = $site['emails'][0] ?? null;
        $row['address'] ??= $site['address'] ?? null;
        $row['pincode'] ??= $site['pincode'] ?? null;
        if (! is_numeric($row['lat']) && isset($site['latitude'], $site['longitude'])) {
            [$row['lat'], $row['lng']] = [$site['latitude'], $site['longitude']];
        }

        foreach (array_slice($site['timings'] ?? [], 0, 12) as $t) {
            $row['timings'][] = [
                'kind' => in_array($t['kind'] ?? null, ['general', 'darshan', 'aarti', 'special'], true) ? $t['kind'] : 'general',
                'label' => $t['label'] ?? null,
                'opens' => OfficialSiteReader::fromTwelveHour($t['opens_at'] ?? null),
                'closes' => OfficialSiteReader::fromTwelveHour($t['closes_at'] ?? null),
                'days' => $t['days'] ?? null,
            ];
            $row['timings_source'] ??= $t['source'] ?? null;
        }
        foreach (array_slice($site['sevas'] ?? [], 0, 15) as $s) {
            // The fee is left behind: the site shows no price it has not checked.
            $row['pujas'][] = [
                'name' => $s['name'],
                'description' => null,
                'starts_at' => OfficialSiteReader::fromTwelveHour($s['starts_at'] ?? null),
                'duration_minutes' => is_numeric($s['duration_minutes'] ?? null) ? (int) $s['duration_minutes'] : null,
                'schedule_note' => $s['note'] ?? null,
                'is_free' => false,
            ];
        }
    }

    private function fromMap(array &$row, array &$sources): void
    {
        $place = OfficialSiteImport::placeAt((float) $row['lat'], (float) $row['lng']);
        if ($place === null) {
            return;
        }
        $sources[] = ['name' => 'OpenStreetMap', 'url' => sprintf('https://www.openstreetmap.org/?mlat=%.5f&mlon=%.5f#map=17/%.5f/%.5f', $row['lat'], $row['lng'], $row['lat'], $row['lng'])];
        $row['address'] ??= $place['address'] ?? null;
        $row['pincode'] ??= $place['pincode'] ?? null;
        $row['city'] ??= $place['city'] ?? null;
        if (filled($place['district'] ?? null)) {
            $row['district'] = trim(preg_replace('/\s+district$/i', '', (string) $place['district']));
        }
    }

    /** The Wikidata item of a temple known only by name, through Wikipedia's search. */
    private function findItem(string $name, State $state): ?string
    {
        $found = $this->json(Http::withHeaders($this->headers())->timeout(20)->get(self::WIKIPEDIA_API, [
            'action' => 'query', 'format' => 'json', 'redirects' => 1, 'prop' => 'pageprops', 'ppprop' => 'wikibase_item',
            'generator' => 'search', 'gsrsearch' => $name.' '.$state->name, 'gsrlimit' => 1,
        ]));
        foreach ($found['query']['pages'] ?? [] as $page) {
            return $page['pageprops']['wikibase_item'] ?? null;
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    private function entity(string $qid): ?array
    {
        return $this->json(Http::withHeaders($this->headers())->timeout(20)->get(self::WIKIDATA_ENTITY.$qid.'.json'))['entities'][$qid] ?? null;
    }

    /**
     * @param  array<int, string>  $ids
     * @return array<string, string> English labels by Q-number
     */
    private function labels(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $entities = $this->json(Http::withHeaders($this->headers())->timeout(20)->get(self::WIKIDATA_API, [
            'action' => 'wbgetentities', 'format' => 'json', 'props' => 'labels', 'languages' => 'en', 'ids' => implode('|', array_unique($ids)),
        ]))['entities'] ?? [];

        return array_filter(array_map(fn ($e) => $e['labels']['en']['value'] ?? null, $entities));
    }

    /** The article as plain text, with "== Heading ==" lines between sections. */
    private function article(string $title): ?string
    {
        $pages = $this->json(Http::withHeaders($this->headers())->timeout(20)->get(self::WIKIPEDIA_API, [
            'action' => 'query', 'format' => 'json', 'redirects' => 1, 'prop' => 'extracts',
            'explaintext' => 1, 'exsectionformat' => 'wiki', 'titles' => $title,
        ]))['query']['pages'] ?? [];
        foreach ($pages as $page) {
            return filled($page['extract'] ?? null) ? (string) $page['extract'] : null;
        }

        return null;
    }

    /** @return array<string, string> section text by heading; the lead under '' */
    public static function sections(string $text): array
    {
        $sections = [];
        $heading = '';
        foreach (preg_split('/\R/', $text) as $line) {
            if (preg_match('/^=+\s*(.+?)\s*=+$/', trim($line), $m)) {
                $heading = $m[1];

                continue;
            }
            $sections[$heading] = trim(($sections[$heading] ?? '').' '.trim($line));
        }

        return $sections;
    }

    /** The first sentences of a text, whole, within a length. */
    public static function sentences(string $text, int $count, int $max): ?string
    {
        $text = trim(preg_replace('/\s+/', ' ', preg_replace('/\[\d+\]/', '', $text)));
        if ($text === '') {
            return null;
        }
        $out = '';
        foreach (array_slice(preg_split('/(?<=[.!?])\s+(?=[A-Z])/', $text) ?: [], 0, $count) as $sentence) {
            if (mb_strlen($out.' '.$sentence) > $max) {
                break;
            }
            $out = trim($out.' '.$sentence);
        }

        return $out !== '' ? $out : Str::limit($text, $max - 3);
    }

    /** One of the site's deities named in a text, or null. */
    public static function deity(string $text): ?string
    {
        $words = preg_split('/[^a-z]+/', mb_strtolower(Str::ascii($text)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach (self::DEITY_WORDS as $deity => $names) {
            foreach ($words as $word) {
                foreach ($names as $n) {
                    if ($word === $n || (mb_strlen($n) >= 5 && str_contains($word, $n))) {
                        return $deity;
                    }
                }
            }
        }

        return null;
    }

    /** "+1213-00-00T00:00:00Z" at year precision → "1213 CE"; century precision → "13th century". */
    private static function period(array $time): ?string
    {
        if (! preg_match('/^([+-])0*(\d+)-/', (string) ($time['time'] ?? ''), $m)) {
            return null;
        }
        $year = (int) $m[2];
        $era = $m[1] === '-' ? ' BCE' : '';
        if (($time['precision'] ?? 9) <= 7) {
            $century = (int) ceil($year / 100);

            return $century.match (true) {
                in_array($century % 100, [11, 12, 13], true) => 'th',
                $century % 10 === 1 => 'st',
                $century % 10 === 2 => 'nd',
                $century % 10 === 3 => 'rd',
                default => 'th',
            }.' century'.$era;
        }

        return $year.($era ?: ' CE');
    }

    /** @return array{0: ?float, 1: ?float} from "Point(lng lat)" */
    private static function point(?string $wkt): array
    {
        return $wkt !== null && preg_match('/Point\(([-\d.]+) ([-\d.]+)\)/', $wkt, $m) ? [(float) $m[2], (float) $m[1]] : [null, null];
    }

    /** The first value of a Wikidata property. */
    private static function value(array $claims, string $property): mixed
    {
        foreach ($claims[$property] ?? [] as $claim) {
            if (($claim['rank'] ?? 'normal') !== 'deprecated' && isset($claim['mainsnak']['datavalue']['value'])) {
                return $claim['mainsnak']['datavalue']['value'];
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        return $response->successful() ? (array) $response->json() : [];
    }

    /** Wikimedia asks every tool to say who it is. */
    private function headers(): array
    {
        return ['User-Agent' => config('brand.name').' temple directory ('.config('brand.support_email').')'];
    }
}
