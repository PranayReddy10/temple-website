<?php

namespace App\Services\Osm;

use App\Enums\VerificationStatus;
use App\Models\Temple;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Finds a temple's Wikipedia article and takes from it what is safe to take.
 *
 * The article is found the way a careful editor would: the one OpenStreetMap
 * links (its wikipedia tag), else the one its Wikidata item links, else an
 * English article about a place within half a kilometre whose title names
 * this temple. Nothing is guessed from a name alone.
 *
 * From the article: its link, its Wikidata item (which gives the Commons
 * photo step a lead), and — only where the temple has no description and no
 * editor owns it — the article's opening as the description. Wikipedia's
 * text is CC BY-SA, so a description taken from it is marked and is shown
 * with "From Wikipedia" and the licence; an editor rewriting it clears that.
 */
class TempleWikipediaFinder
{
    protected const WIKIDATA = 'https://www.wikidata.org/w/api.php';

    /** Nearby articles further than this are not this temple. */
    public const NEARBY_METRES = 500;

    /** The opening is cut at a sentence end at or before this. */
    protected const DESCRIPTION_LENGTH = 600;

    public function __construct(protected OsmTempleImporter $importer) {}

    /**
     * The temple's article as [language, title], or null.
     *
     * @return array{0: string, 1: string}|null
     */
    public function articleFor(Temple $temple): ?array
    {
        return $this->articleForPlace(
            $temple->wikipedia_url,
            $temple->wikidata_id,
            $temple->hasCoordinates() ? (float) $temple->latitude : null,
            $temple->hasCoordinates() ? (float) $temple->longitude : null,
            [$temple->name, ...$temple->aliases()->pluck('name')],
        );
    }

    /**
     * The article for a place not yet saved (an import being reviewed): its
     * article link, its Wikidata item, or an article near the pin.
     *
     * @param  list<string>  $names
     * @return array{0: string, 1: string}|null
     */
    public function articleForPlace(?string $wikipediaUrl, ?string $wikidata, ?float $lat, ?float $lon, array $names): ?array
    {
        if (filled($wikipediaUrl) && preg_match('~^https?://([a-z]{2,3})\.(?:m\.)?wikipedia\.org/wiki/(.+)$~i', $wikipediaUrl, $m) === 1) {
            return [strtolower($m[1]), str_replace('_', ' ', rawurldecode($m[2]))];
        }

        if (filled($wikidata)) {
            $links = $this->client()->get(self::WIKIDATA, [
                'action' => 'wbgetentities', 'format' => 'json', 'ids' => $wikidata,
                'props' => 'sitelinks', 'sitefilter' => 'enwiki|tewiki|hiwiki',
            ])->throw()->json('entities.'.$wikidata.'.sitelinks') ?? [];

            foreach (['en', 'te', 'hi'] as $lang) {
                if (filled($links[$lang.'wiki']['title'] ?? null)) {
                    return [$lang, $links[$lang.'wiki']['title']];
                }
            }
        }

        return $lat !== null && $lon !== null ? $this->nearby($lat, $lon, $names) : null;
    }

    /**
     * An English article about a place near the pin whose title names it.
     *
     * @param  list<string>  $names
     * @return array{0: string, 1: string}|null
     */
    protected function nearby(float $lat, float $lon, array $names): ?array
    {
        $places = $this->client()->get('https://en.wikipedia.org/w/api.php', [
            'action' => 'query', 'format' => 'json', 'list' => 'geosearch',
            'gscoord' => $lat.'|'.$lon,
            'gsradius' => self::NEARBY_METRES, 'gslimit' => 20,
        ])->throw()->json('query.geosearch') ?? [];

        foreach ($places as $place) {
            $title = (string) ($place['title'] ?? '');
            // "Hanuman Temple, Karimnagar": the part before the comma.
            $bare = trim(Str::before($title, ','));
            foreach (array_filter($names) as $name) {
                if ($this->importer->sameName((string) $name, $bare)) {
                    return ['en', $title];
                }
            }
        }

        return null;
    }

    /**
     * The article's opening and its Wikidata item, or null for a missing
     * page or a disambiguation page.
     *
     * @return array{lang: string, title: string, url: string, extract: ?string, wikidata: ?string}|null
     */
    public function summary(string $lang, string $title): ?array
    {
        $response = $this->client()->get('https://'.$lang.'.wikipedia.org/api/rest_v1/page/summary/'.rawurlencode(str_replace(' ', '_', $title)));

        if ($response->status() === 404) {
            return null;
        }
        $page = $response->throw()->json();
        if (! is_array($page) || ($page['type'] ?? '') === 'disambiguation') {
            return null;
        }

        return [
            'lang' => $lang,
            'title' => (string) ($page['title'] ?? $title),
            'url' => (string) ($page['content_urls']['desktop']['page'] ?? 'https://'.$lang.'.wikipedia.org/wiki/'.rawurlencode(str_replace(' ', '_', $title))),
            'extract' => filled($page['extract'] ?? null) ? Str::squish((string) $page['extract']) : null,
            'wikidata' => preg_match('/^Q\d+$/', (string) ($page['wikibase_item'] ?? '')) === 1 ? $page['wikibase_item'] : null,
        ];
    }

    /**
     * Takes from the article what is safe to take. Returns what changed.
     *
     * @param  array{lang: string, title: string, url: string, extract: ?string, wikidata: ?string}  $article
     * @return list<string>
     */
    public function apply(Temple $temple, array $article): array
    {
        $fill = [];
        $changed = [];

        if (blank($temple->wikipedia_url)) {
            $fill['wikipedia_url'] = Str::limit($article['url'], 500, '');
            $changed[] = 'article';
        }

        // One Wikidata item, one temple.
        if (blank($temple->wikidata_id) && $article['wikidata'] !== null
            && ! Temple::withTrashed()->where('wikidata_id', $article['wikidata'])->exists()) {
            $fill['wikidata_id'] = $article['wikidata'];
            $changed[] = 'Wikidata item';
        }

        // The description only in English, only where there is none, and
        // never on a temple an editor has checked.
        if ($article['lang'] === 'en' && blank($temple->short_description) && ! $this->editorOwns($temple)
            && ($opening = $this->opening($article['extract'])) !== null) {
            $fill['short_description'] = $opening;
            $fill['description_source'] = 'wikipedia';
            $changed[] = 'description';
        }

        // History and significance, the same way: only empty fields, only
        // where no editor owns the temple, and listed for the credit.
        $taken = $temple->wikipedia_fields ?? [];
        if (! $this->editorOwns($temple)) {
            foreach (['history', 'significance'] as $field) {
                if (filled($article[$field] ?? null) && blank($temple->getAttribute($field))) {
                    $fill[$field] = $article[$field];
                    $taken[] = $field;
                    $changed[] = $field;
                }
            }
        }
        if ($taken !== ($temple->wikipedia_fields ?? [])) {
            $fill['wikipedia_fields'] = array_values(array_unique($taken));
        }

        if ($fill !== []) {
            // Quietly: only references and an empty field are written, and
            // a temple team's own rules for editing are not in question.
            $temple->forceFill($fill)->saveQuietly();
        }

        return $changed;
    }

    /** Longest history or significance taken, cut at a sentence. */
    protected const SECTION_LENGTH = 1500;

    /**
     * Which of the article's sections fill which field, best match first.
     * A temple article usually has "History", and "Legend", "Significance"
     * or "Religious significance" for why devotees come.
     */
    protected const SECTIONS = [
        'history' => '/^(history|historical background|origins?|construction|early history)$/i',
        'significance' => '/^((religious |spiritual |cultural )?significance|importance|legends?|mythology|sthala purana|sthalapuranam|puranic (story|significance)|beliefs?)$/i',
    ];

    /**
     * The article's History and Significance (or Legend) sections as plain
     * text, each cut at a sentence. Only English articles.
     *
     * @return array{history?: string, significance?: string}
     */
    public function sections(string $lang, string $title): array
    {
        if ($lang !== 'en') {
            return [];
        }

        // The sections are extra: if they cannot be read, the article's
        // link and opening still are.
        try {
            $pages = $this->client()->get('https://en.wikipedia.org/w/api.php', [
                'action' => 'query', 'format' => 'json', 'formatversion' => 2,
                'prop' => 'extracts', 'explaintext' => 1, 'exsectionformat' => 'wiki',
                'redirects' => 1, 'titles' => $title,
            ])->throw()->json('query.pages') ?? [];
        } catch (\Throwable) {
            return [];
        }

        return $this->sectionsFrom((string) ($pages[0]['extract'] ?? ''));
    }

    /**
     * Plain article text ("== History ==" headings) to the fields. Pure,
     * for testing.
     *
     * @return array{history?: string, significance?: string}
     */
    public function sectionsFrom(string $text): array
    {
        // Split on top-level headings; a sub-section stays with its parent.
        $parts = preg_split('/^==\s*([^=].*?)\s*==\s*$/m', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $sections = [];
        for ($i = 1; $i + 1 < count($parts); $i += 2) {
            $body = preg_replace('/^===+\s*.*?\s*===+\s*$/m', '', $parts[$i + 1]);
            $sections[] = [trim($parts[$i]), Str::squish((string) $body)];
        }

        $out = [];
        foreach (self::SECTIONS as $field => $pattern) {
            foreach ($sections as [$heading, $body]) {
                if (preg_match($pattern, $heading) === 1 && mb_strlen($body) >= 80) {
                    $out[$field] = $this->cut($body, self::SECTION_LENGTH);
                    break;
                }
            }
        }

        return $out;
    }

    protected function cut(string $text, int $length): string
    {
        if (mb_strlen($text) <= $length) {
            return $text;
        }
        $cut = mb_substr($text, 0, $length);
        $end = (int) mb_strrpos($cut, '. ');

        return $end > 80 ? mb_substr($cut, 0, $end + 1) : Str::limit($text, $length);
    }

    /** The article's opening, cut at the end of a sentence. */
    public function opening(?string $extract): ?string
    {
        if ($extract === null || mb_strlen($extract) < 40) {
            return null;
        }
        if (mb_strlen($extract) <= self::DESCRIPTION_LENGTH) {
            return $extract;
        }

        $cut = mb_substr($extract, 0, self::DESCRIPTION_LENGTH);
        $end = max((int) mb_strrpos($cut, '. '), (int) mb_strrpos($cut, '.'));

        return $end > 80 ? mb_substr($cut, 0, $end + 1) : Str::limit($extract, self::DESCRIPTION_LENGTH);
    }

    protected function editorOwns(Temple $temple): bool
    {
        return $temple->trashed()
            || in_array($temple->verification_status, [VerificationStatus::Verified, VerificationStatus::Official], true)
            || $temple->last_verified_at !== null;
    }

    protected function client(): PendingRequest
    {
        // Wikimedia asks every client to say who it is.
        return Http::withHeaders([
            'User-Agent' => config('brand.name').'/1.0 ('.config('brand.url').'; '.config('brand.support_email').')',
        ])->timeout(20);
    }
}
