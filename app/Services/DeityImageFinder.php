<?php

namespace App\Services;

use App\Models\Deity;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Finds a public-domain painting of a deity on Wikimedia Commons and keeps it
 * as the deity's image, with its credit.
 *
 * Only files Commons itself marks public domain are taken — in practice the
 * oleographs of Raja Ravi Varma (1848–1906) and his press, which are what
 * most of India pictures when it pictures these deities. Anything under a
 * licence that asks for more than a credit is skipped rather than guessed
 * at. An editor can replace any image from the deity's page.
 */
class DeityImageFinder
{
    protected const API = 'https://commons.wikimedia.org/w/api.php';

    /** Longest edge of the copy we keep. */
    protected const WIDTH = 900;

    /**
     * What to search Commons for, per deity. The painter's name keeps the
     * results to classical paintings rather than photographs of murtis,
     * which are nearly always someone's copyright.
     *
     * @var array<string, list<string>>
     */
    public const QUERIES = [
        'shiva' => ['Ravi Varma Shiva', 'Ravi Varma Press Shiva', 'Shiva painting 19th century'],
        'vishnu' => ['Ravi Varma Vishnu', 'Ravi Varma Press Vishnu', 'Vishnu painting 19th century'],
        'venkateswara' => ['Venkateswara painting Ravi Varma Press', 'Balaji Tirupati painting 19th century', 'Venkateswara Tanjore painting'],
        'krishna' => ['Ravi Varma Krishna', 'Ravi Varma Press Krishna', 'Krishna painting 19th century'],
        'rama' => ['Ravi Varma Rama', 'Ravi Varma Press Rama', 'Rama painting 19th century'],
        'narasimha' => ['Ravi Varma Narasimha', 'Narasimha painting 19th century', 'Narasimha Ravi Varma Press'],
        'devi' => ['Ravi Varma Durga', 'Ravi Varma Press Durga', 'Durga painting 19th century'],
        'lakshmi' => ['Ravi Varma Lakshmi', 'Ravi Varma Press Lakshmi', 'Lakshmi painting 19th century'],
        'saraswati' => ['Ravi Varma Saraswati', 'Ravi Varma Press Saraswati', 'Saraswati painting 19th century'],
        'kali' => ['Ravi Varma Kali', 'Ravi Varma Press Kali', 'Kali painting 19th century'],
        'ganesha' => ['Ravi Varma Ganesha', 'Ravi Varma Press Ganesh', 'Ganesha painting 19th century'],
        'hanuman' => ['Ravi Varma Hanuman', 'Ravi Varma Press Hanuman', 'Hanuman painting 19th century'],
        'surya' => ['Ravi Varma Surya', 'Surya painting 19th century', 'Surya deity painting'],
        'ayyappa' => ['Ayyappa painting', 'Sastha painting Kerala', 'Ayyappan painting'],
    ];

    /**
     * The best public-domain candidate, or null when Commons has none.
     *
     * @return array{title: string, url: string, page: string, artist: ?string, licence: string}|null
     */
    public function find(Deity $deity): ?array
    {
        $queries = self::QUERIES[$deity->slug] ?? ["Ravi Varma {$deity->name}", "{$deity->name} painting 19th century"];

        foreach ($queries as $query) {
            $titles = $this->search($query);

            if ($titles === []) {
                continue;
            }

            foreach ($this->describe($titles) as $candidate) {
                if ($this->isPublicDomain($candidate['licence'])) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * Downloads the candidate and makes it the deity's image.
     *
     * @param  array{title: string, url: string, page: string, artist: ?string, licence: string}  $candidate
     */
    public function store(Deity $deity, array $candidate): void
    {
        $response = $this->client()->timeout(30)->get($candidate['url'])->throw();

        $extension = Str::of(parse_url($candidate['url'], PHP_URL_PATH) ?? '')->afterLast('.')->lower()->toString();
        $extension = in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true) ? $extension : 'jpg';

        $disk = (string) config('filesystems.media');
        $path = 'deities/'.$deity->slug.'-commons-'.substr(sha1($candidate['url']), 0, 8).'.'.$extension;

        Storage::disk($disk)->put($path, $response->body(), 'public');

        $old = [$deity->image_disk, $deity->image_path];

        $deity->forceFill([
            'image_disk' => $disk,
            'image_path' => $path,
            'image_credit' => Str::limit(
                trim(($candidate['artist'] ? $candidate['artist'].', ' : '').'via Wikimedia Commons ('.$candidate['licence'].') · '.$candidate['page']),
                255,
                '',
            ),
        ])->save();

        // Only a file this finder stored earlier is ours to remove; an
        // editor's own upload is left where it is.
        if ($old[1] !== null && $old[1] !== $path && str_contains($old[1], '-commons-')) {
            Storage::disk($old[0] ?? $disk)->delete($old[1]);
        }
    }

    /** @return list<string> */
    protected function search(string $query): array
    {
        try {
            $json = $this->client()->get(self::API, [
                'action' => 'query',
                'format' => 'json',
                'list' => 'search',
                'srsearch' => $query.' filetype:bitmap',
                'srnamespace' => 6,
                'srlimit' => 8,
            ])->json();
        } catch (ConnectionException) {
            return [];
        }

        return collect($json['query']['search'] ?? [])->pluck('title')->filter()->values()->all();
    }

    /**
     * @param  list<string>  $titles
     * @return list<array{title: string, url: string, page: string, artist: ?string, licence: string}>
     */
    protected function describe(array $titles): array
    {
        try {
            $json = $this->client()->get(self::API, [
                'action' => 'query',
                'format' => 'json',
                'titles' => implode('|', $titles),
                'prop' => 'imageinfo',
                'iiprop' => 'url|mime|extmetadata',
                'iiurlwidth' => self::WIDTH,
            ])->json();
        } catch (ConnectionException) {
            return [];
        }

        $pages = collect($json['query']['pages'] ?? [])->keyBy('title');

        // Keep Commons' own search order: the first match is the best one.
        return collect($titles)
            ->map(fn (string $title) => $pages->get($title))
            ->filter(fn ($page) => isset($page['imageinfo'][0]))
            ->map(function (array $page): ?array {
                $info = $page['imageinfo'][0];

                if (! in_array($info['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true)) {
                    return null;
                }

                $meta = $info['extmetadata'] ?? [];

                return [
                    'title' => $page['title'],
                    'url' => $info['thumburl'] ?? $info['url'],
                    'page' => $info['descriptionurl'] ?? '',
                    'artist' => filled($meta['Artist']['value'] ?? null) ? Str::of(strip_tags($meta['Artist']['value']))->squish()->limit(80, '')->toString() : null,
                    'licence' => (string) ($meta['LicenseShortName']['value'] ?? ''),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    protected function isPublicDomain(string $licence): bool
    {
        $licence = Str::lower($licence);

        return str_contains($licence, 'public domain') || preg_match('/^pd[\s-]/', $licence) === 1 || $licence === 'pd' || str_contains($licence, 'cc0');
    }

    protected function client(): \Illuminate\Http\Client\PendingRequest
    {
        // Wikimedia asks every client to say who it is.
        return Http::withHeaders([
            'User-Agent' => config('brand.name').'/1.0 ('.config('brand.url').'; '.config('brand.support_email').')',
        ])->timeout(15);
    }
}
