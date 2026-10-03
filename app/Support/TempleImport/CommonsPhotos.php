<?php

namespace App\Support\TempleImport;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Freely licensed photos taken at a place, from Wikimedia Commons.
 *
 * Commons only holds files under free licences (CC BY, CC BY-SA, CC0,
 * public domain), so these may be copied into the gallery as long as the
 * photographer and the licence go with them; each candidate carries both.
 * Photos are found by where they were taken, nearest first, with those
 * whose title names the temple ahead.
 */
final class CommonsPhotos
{
    public const API = 'https://commons.wikimedia.org/w/api.php';

    /**
     * @return array<int, array{url: string, thumb: string, width: int, height: int, title: string, credit: string, license: string, license_url: ?string, source_url: string, distance: ?int}>
     */
    public static function near(float $latitude, float $longitude, ?string $name = null, int $radius = 600): array
    {
        try {
            $response = Http::timeout(15)->withHeaders(['User-Agent' => config('brand.name').' temple directory ('.config('brand.website').')'])
                ->get(self::API, [
                    'action' => 'query', 'format' => 'json', 'formatversion' => 2,
                    'generator' => 'geosearch', 'ggscoord' => $latitude.'|'.$longitude,
                    'ggsradius' => $radius, 'ggsnamespace' => 6, 'ggslimit' => 40,
                    'prop' => 'imageinfo|coordinates', 'iiprop' => 'url|size|mime|extmetadata', 'iiurlwidth' => 1600,
                ]);
            $pages = $response->successful() ? ($response->json('query.pages') ?? []) : [];
        } catch (Throwable) {
            return [];
        }

        $words = collect(preg_split('/\W+/u', mb_strtolower((string) $name)))->filter(fn ($w) => mb_strlen($w) > 3 && ! in_array($w, ['temple', 'swamy', 'swami', 'devasthanam', 'mandir', 'gudi', 'sri', 'shri'], true));
        $out = [];
        foreach ($pages as $page) {
            $info = $page['imageinfo'][0] ?? null;
            if (! is_array($info) || ! in_array($info['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true) || ($info['width'] ?? 0) < 800) {
                continue;
            }
            $meta = $info['extmetadata'] ?? [];
            $license = self::text($meta['LicenseShortName']['value'] ?? '');
            // Commons allows nothing else, but a file still being checked
            // can carry a bad tag: only clearly free licences are offered.
            if ($license === '' || ! preg_match('/^(cc[ -]by(-sa)?|cc0|public domain|pd)/i', $license) || preg_match('/\bnc\b|\bnd\b/i', $license)) {
                continue;
            }
            $title = Str::of((string) ($page['title'] ?? ''))->after('File:')->beforeLast('.')->replace('_', ' ')->toString();
            $distance = isset($page['coordinates'][0]['dist']) ? (int) round($page['coordinates'][0]['dist']) : null;
            $named = $words->filter(fn ($w) => str_contains(mb_strtolower($title), $w))->count();
            $out[] = [
                'url' => (string) ($info['thumburl'] ?? $info['url']),
                'thumb' => (string) ($info['thumburl'] ?? $info['url']),
                'width' => (int) ($info['thumbwidth'] ?? $info['width']),
                'height' => (int) ($info['thumbheight'] ?? $info['height']),
                'title' => $title,
                'credit' => Str::limit(self::text($meta['Artist']['value'] ?? '') ?: 'Wikimedia Commons contributor', 120, ''),
                'license' => $license,
                'license_url' => self::text($meta['LicenseUrl']['value'] ?? '') ?: null,
                'source_url' => (string) ($info['descriptionurl'] ?? ''),
                'distance' => $distance,
                'score' => $named * 1000 - ($distance ?? $radius),
            ];
        }

        usort($out, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice(array_map(fn ($p) => array_diff_key($p, ['score' => true]), $out), 0, 16);
    }

    private static function text(string $html): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }
}
