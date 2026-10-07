<?php

namespace App\Support;

use App\Models\Page;
use App\Models\Setting;
use App\Models\Temple;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Tells search engines a page is new or changed, instead of waiting for
 * them to come round to the sitemap.
 *
 * IndexNow is read by Bing, Yandex, Seznam and Naver (and through Bing,
 * by DuckDuckGo and others). Google does not take pings any more: it reads
 * the sitemap submitted once in Search Console and comes back on its own.
 * The key is made once and kept in settings; the file proving it is served
 * at the site's root.
 */
final class IndexNow
{
    public const ENDPOINT = 'https://api.indexnow.org/indexnow';

    /** At most this many URLs per request, as the protocol allows. */
    public const BATCH = 10000;

    public static function key(): string
    {
        $key = (string) Setting::get('indexnow_key');

        if (preg_match('/^[0-9a-f]{32}$/', $key) !== 1) {
            $key = Str::lower(bin2hex(random_bytes(16)));
            Setting::set('indexnow_key', $key);
        }

        return $key;
    }

    /**
     * Submits the website's URLs. Returns how many were sent, or null when
     * the search engines could not be reached (tried again next run).
     *
     * @param  array<int, string>  $urls
     */
    public static function submit(array $urls): ?int
    {
        $urls = array_values(array_unique(array_filter($urls)));
        if ($urls === []) {
            return 0;
        }

        $host = (string) parse_url(Seo::website(), PHP_URL_HOST);
        $key = self::key();
        $sent = 0;

        foreach (array_chunk($urls, self::BATCH) as $batch) {
            try {
                $response = Http::timeout(20)->acceptJson()->post(self::ENDPOINT, [
                    'host' => $host,
                    'key' => $key,
                    'keyLocation' => Seo::url($key.'.txt'),
                    'urlList' => $batch,
                ]);
                if (! $response->successful() && $response->status() !== 202) {
                    return null;
                }
            } catch (Throwable) {
                return null;
            }
            $sent += count($batch);
        }

        return $sent;
    }

    /**
     * The pages changed since a time (all of them when null): each changed
     * temple, the state and deity pages that list it, the directory, and
     * any changed information page.
     *
     * @return array<int, string>
     */
    public static function templeUrls(?\DateTimeInterface $since = null): array
    {
        $temples = Temple::query()->published()
            ->when($since !== null, fn ($q) => $q->where('updated_at', '>', $since))
            ->with(['state:id,slug', 'deity:id,slug', 'district:id,slug', 'translations' => fn ($q) => $q->where('field', 'name')->where('is_reviewed', true)])
            ->orderBy('id')
            ->get(['id', 'slug', 'state_id', 'deity_id', 'district_id']);

        $pages = Page::query()->published()
            ->when($since !== null, fn ($q) => $q->where('updated_at', '>', $since))
            ->pluck('slug');

        return collect([Seo::url('temples')])
            ->merge($temples->map(fn (Temple $t): string => Seo::url('temples/'.$t->slug)))
            // The page in each language it is published in.
            ->merge($temples->flatMap(fn (Temple $t): array => array_map(fn (string $code): string => SiteLocale::templeUrl($t, $code), SiteLocale::languagesOf($t))))
            ->merge($temples->filter(fn (Temple $t): bool => $t->state?->slug !== null && $t->district?->slug !== null)
                ->map(fn (Temple $t): string => Seo::url('states/'.$t->state->slug.'/'.$t->district->slug))->unique())
            ->merge($temples->pluck('state.slug')->filter()->unique()->map(fn (string $s): string => Seo::url('states/'.$s)))
            ->merge($temples->pluck('deity.slug')->filter()->unique()->map(fn (string $s): string => Seo::url('deities/'.$s)))
            ->merge($pages->map(fn (string $s): string => Seo::url($s)))
            ->values()
            ->all();
    }
}
