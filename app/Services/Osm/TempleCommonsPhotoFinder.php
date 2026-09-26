<?php

namespace App\Services\Osm;

use App\Enums\PhotoCategory;
use App\Models\Temple;
use App\Models\TemplePhoto;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Finds a freely licensed photograph of a temple on Wikimedia Commons and
 * adds it to the temple's gallery with its photographer and licence.
 *
 * Only two leads are followed, because both point at a photo someone has
 * already said is of this temple: the Commons file OpenStreetMap names for it,
 * and the image on its Wikidata item. Photos merely taken nearby are not
 * guessed at.
 *
 * Only public domain, CC0, CC BY and CC BY-SA files are taken. All of them
 * allow reuse in an app as long as the credit and licence are shown, which
 * the photo API already does. Anything else is skipped rather than guessed.
 */
class TempleCommonsPhotoFinder
{
    protected const COMMONS = 'https://commons.wikimedia.org/w/api.php';

    protected const WIKIDATA = 'https://www.wikidata.org/w/api.php';

    /** Longest edge of the copy we keep; the photo processor makes the smaller sizes. */
    protected const WIDTH = 1600;

    /**
     * The Commons file for a temple, from OSM's lead or Wikidata's image.
     */
    public function fileFor(Temple $temple): ?string
    {
        if (filled($temple->commons_image)) {
            return $temple->commons_image;
        }

        if (blank($temple->wikidata_id)) {
            return null;
        }

        $claims = $this->client()->get(self::WIKIDATA, [
            'action' => 'wbgetclaims',
            'format' => 'json',
            'entity' => $temple->wikidata_id,
            'property' => 'P18',
        ])->throw()->json('claims.P18') ?? [];

        $file = $claims[0]['mainsnak']['datavalue']['value'] ?? null;

        return is_string($file) && $file !== '' ? 'File:'.$file : null;
    }

    /**
     * What Commons says about the file, or null when it is not an image or
     * not under a licence we can use.
     *
     * @return array{title: string, url: string, page: string, artist: ?string, licence: string, caption: ?string}|null
     */
    public function describe(string $file): ?array
    {
        $pages = $this->client()->get(self::COMMONS, [
            'action' => 'query',
            'format' => 'json',
            'titles' => $file,
            'prop' => 'imageinfo',
            'iiprop' => 'url|mime|extmetadata',
            'iiurlwidth' => self::WIDTH,
        ])->throw()->json('query.pages') ?? [];

        $page = collect($pages)->first(fn (array $p) => isset($p['imageinfo'][0]));

        if ($page === null) {
            return null;
        }

        $info = $page['imageinfo'][0];

        if (! in_array($info['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return null;
        }

        $meta = $info['extmetadata'] ?? [];
        $licence = trim(strip_tags((string) ($meta['LicenseShortName']['value'] ?? '')));

        if (! $this->isReusable($licence)) {
            return null;
        }

        $clean = fn (?string $html, int $limit): ?string => filled($html)
            ? Str::of(strip_tags(html_entity_decode($html)))->squish()->limit($limit, '')->toString()
            : null;

        return [
            'title' => $page['title'],
            'url' => $info['thumburl'] ?? $info['url'],
            'page' => $info['descriptionurl'] ?? 'https://commons.wikimedia.org/wiki/'.rawurlencode(str_replace(' ', '_', $page['title'])),
            'artist' => $clean($meta['Artist']['value'] ?? null, 120),
            'licence' => $licence,
            'caption' => $clean($meta['ImageDescription']['value'] ?? null, 250),
        ];
    }

    /**
     * Downloads the file into the temple's gallery. The photo observer makes
     * the display sizes, and makes it the lead image if the temple has none.
     *
     * @param  array{title: string, url: string, page: string, artist: ?string, licence: string, caption: ?string}  $candidate
     */
    public function store(Temple $temple, array $candidate): TemplePhoto
    {
        $response = $this->client()->timeout(60)->get($candidate['url'])->throw();

        $extension = Str::of(parse_url($candidate['url'], PHP_URL_PATH) ?? '')->afterLast('.')->lower()->toString();
        $extension = in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true) ? $extension : 'jpg';

        $disk = (string) config('filesystems.media');
        $path = 'temples/'.$temple->getKey().'/commons-'.substr(sha1($candidate['page']), 0, 10).'.'.$extension;

        Storage::disk($disk)->put($path, $response->body(), 'public');

        return $temple->photos()->create([
            'disk' => $disk,
            'path' => $path,
            'category' => PhotoCategory::Exterior,
            'caption' => $candidate['caption'],
            'credit' => Str::limit(($candidate['artist'] ?? 'Unknown photographer').', via Wikimedia Commons', 255, ''),
            'source_url' => Str::limit($candidate['page'], 255, ''),
            'license' => Str::limit($candidate['licence'], 255, ''),
            'is_published' => true,
            'sort_order' => 0,
        ]);
    }

    /** Whether the photo already hangs in this temple's gallery. */
    public function alreadyHas(Temple $temple, string $page): bool
    {
        return $temple->photos()->where('source_url', Str::limit($page, 255, ''))->exists();
    }

    public function isReusable(string $licence): bool
    {
        $licence = Str::lower($licence);

        // Non-commercial and no-derivatives files are not allowed on Commons,
        // but a mislabelled one must still not slip through.
        if (preg_match('/\b(nc|nd)\b/', str_replace('-', ' ', $licence)) === 1) {
            return false;
        }

        return str_contains($licence, 'public domain')
            || preg_match('/^pd([\s-]|$)/', $licence) === 1
            || str_contains($licence, 'cc0')
            || preg_match('/^cc[\s-]by([\s-]sa)?([\s-][\d.]+.*)?$/', $licence) === 1;
    }

    protected function client(): PendingRequest
    {
        // Wikimedia asks every client to say who it is.
        return Http::withHeaders([
            'User-Agent' => config('brand.name').'/1.0 ('.config('brand.url').'; '.config('brand.support_email').')',
        ])->timeout(20);
    }
}
