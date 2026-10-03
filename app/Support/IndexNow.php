<?php

namespace App\Support;

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

    /** The temple pages changed since a time (all of them when null), plus the directory. */
    public static function templeUrls(?\DateTimeInterface $since = null): array
    {
        return Temple::query()->published()
            ->when($since !== null, fn ($q) => $q->where('updated_at', '>', $since))
            ->orderBy('id')
            ->pluck('slug')
            ->map(fn (string $slug): string => Seo::url('temples/'.$slug))
            ->prepend(Seo::url('temples'))
            ->all();
    }
}
