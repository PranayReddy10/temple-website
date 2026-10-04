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
        if (filled($temple->wikipedia_url) && preg_match('~^https?://([a-z]{2,3})\.(?:m\.)?wikipedia\.org/wiki/(.+)$~i', $temple->wikipedia_url, $m) === 1) {
            return [strtolower($m[1]), str_replace('_', ' ', rawurldecode($m[2]))];
        }

        if (filled($temple->wikidata_id)) {
            $links = $this->client()->get(self::WIKIDATA, [
                'action' => 'wbgetentities', 'format' => 'json', 'ids' => $temple->wikidata_id,
                'props' => 'sitelinks', 'sitefilter' => 'enwiki|tewiki|hiwiki',
            ])->throw()->json('entities.'.$temple->wikidata_id.'.sitelinks') ?? [];

            foreach (['en', 'te', 'hi'] as $lang) {
                if (filled($links[$lang.'wiki']['title'] ?? null)) {
                    return [$lang, $links[$lang.'wiki']['title']];
                }
            }

            return null;
        }

        return $this->nearby($temple);
    }

    /**
     * An English article about a place near the temple whose title names it.
     *
     * @return array{0: string, 1: string}|null
     */
    protected function nearby(Temple $temple): ?array
    {
        if (! $temple->hasCoordinates()) {
            return null;
        }

        $places = $this->client()->get('https://en.wikipedia.org/w/api.php', [
            'action' => 'query', 'format' => 'json', 'list' => 'geosearch',
            'gscoord' => $temple->latitude.'|'.$temple->longitude,
            'gsradius' => self::NEARBY_METRES, 'gslimit' => 20,
        ])->throw()->json('query.geosearch') ?? [];

        $names = [$temple->name, ...$temple->aliases()->pluck('name')];
        foreach ($places as $place) {
            $title = (string) ($place['title'] ?? '');
            // "Hanuman Temple, Karimnagar": the part before the comma.
            $bare = trim(Str::before($title, ','));
            foreach ($names as $name) {
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

        if ($fill !== []) {
            // Quietly: only references and an empty field are written, and
            // a temple team's own rules for editing are not in question.
            $temple->forceFill($fill)->saveQuietly();
        }

        return $changed;
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
