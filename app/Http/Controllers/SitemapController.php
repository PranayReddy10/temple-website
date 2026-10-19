<?php

namespace App\Http\Controllers;

use App\Models\Festival;
use App\Models\Page;
use App\Models\Temple;
use App\Support\Seo;
use App\Support\SiteLocale;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * sitemap.xml for the devotees' website: an index of smaller sitemaps, the
 * way search engines prefer once a site grows past a few thousand pages
 * (each file may hold at most 50,000 addresses).
 */
class SitemapController extends Controller
{
    public const PER_FILE = 5000;

    public function index(): Response
    {
        $pages = max(1, (int) ceil(Temple::query()->published()->count() / self::PER_FILE));
        $latest = Temple::query()->published()->max('updated_at');

        $xml = self::declaration().'<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        $xml .= self::entry('sitemap', Seo::url('sitemap-pages.xml'), $latest);
        for ($i = 1; $i <= $pages; $i++) {
            $xml .= self::entry('sitemap', Seo::url("sitemap-temples-{$i}.xml"), $latest);
        }

        return self::xml($xml.'</sitemapindex>');
    }

    public function pages(): Response
    {
        $xml = self::open();
        $xml .= self::entry('url', Seo::url('/'), null, 'daily', '1.0');
        $xml .= self::entry('url', Seo::url('temples'), Temple::query()->published()->max('updated_at'), 'daily', '0.9');
        foreach (PublicTempleController::statesWithTemples() as $state) {
            $xml .= self::entry('url', Seo::url('states/'.$state->slug), null, 'weekly', '0.7');
            foreach (PublicTempleController::districtsWithTemples($state) as $district) {
                $xml .= self::entry('url', Seo::url('states/'.$state->slug.'/'.$district->slug), null, 'weekly', '0.6');
            }
        }
        foreach (PublicTempleController::tagsWithTemples() as $tag) {
            $xml .= self::entry('url', Seo::url('tags/'.$tag->slug), null, 'weekly', '0.6');
        }
        foreach (PublicTempleController::deitiesWithTemples() as $deity) {
            $xml .= self::entry('url', Seo::url('deities/'.$deity->slug), null, 'weekly', '0.7');
        }
        // The festival calendar: the index, each festival, each month, each kind of vrat.
        $xml .= self::entry('url', Seo::url('festivals'), null, 'weekly', '0.8');
        foreach (array_keys(FestivalPageController::GROUPS) as $group) {
            $xml .= self::entry('url', Seo::url('festivals/'.$group), null, 'monthly', '0.7');
        }
        foreach (Festival::query()->published()->selectRaw('slug, max(updated_at) as updated_at, max(is_major) as is_major')->groupBy('slug')->get() as $f) {
            $xml .= self::entry('url', Seo::url('festivals/'.$f->slug), $f->updated_at, 'monthly', $f->is_major ? '0.7' : '0.5');
        }
        foreach (FestivalPageController::monthPaths() as $path) {
            $xml .= self::entry('url', Seo::url('festivals/'.$path), null, 'monthly', '0.5');
        }
        foreach (Page::query()->published()->orderBy('sort_order')->get(['slug', 'updated_at']) as $page) {
            $xml .= self::entry('url', Seo::url($page->slug), $page->updated_at, 'yearly', '0.3');
        }

        return self::xml($xml.'</urlset>');
    }

    public function temples(int $page): Response
    {
        $temples = Temple::query()->published()
            ->orderBy('id')
            ->skip(($page - 1) * self::PER_FILE)->take(self::PER_FILE)
            ->with([
                'primaryPhoto',
                'photos' => fn ($q) => $q->published()->orderByDesc('is_primary')->orderBy('sort_order'),
                'translations' => fn ($q) => $q->where('field', 'name')->where('is_reviewed', true),
                'payoutAccount',
                'deity:id,image_disk,image_path',
            ])
            ->withCount(['pujas' => fn ($q) => $q->published()])
            ->get(['id', 'name', 'slug', 'updated_at', 'is_featured', 'accepts_donations', 'deity_id']);

        abort_if($temples->isEmpty() && $page > 1, 404);

        // With each temple's photos, so they can show in image search, and
        // its pages in other languages, each listing all the versions.
        $xml = self::open(images: true, languages: true);
        foreach ($temples as $t) {
            // One temple's photo on a storage that cannot answer, or its
            // translations, must not take the whole file down: Google then
            // reads an error page and calls the sitemap "not XML". The
            // temple is still listed, without what failed, and the cause is
            // logged.
            try {
                $images = $t->photos->map(fn ($ph) => Seo::absolute($ph->mediumUrl() ?? $ph->url()))
                    ->prepend(Seo::absolute($t->primaryPhoto?->mediumUrl()))
                    ->filter()->unique()->take(20)->values()->all();
                // No photo yet: the deity's painting that the page shows instead.
                if ($images === [] && ($deityImage = Seo::absolute($t->deity?->imageUrl()))) {
                    $images = [$deityImage];
                }
            } catch (Throwable $e) {
                report($e);
                $images = [];
            }
            try {
                $alternates = SiteLocale::alternates($t, SiteLocale::languagesOf($t));
            } catch (Throwable $e) {
                report($e);
                $alternates = [];
            }

            foreach ($alternates === [] ? ['en' => Seo::url('temples/'.$t->slug)] : array_diff_key($alternates, ['x-default' => true]) as $code => $loc) {
                $xml .= self::entry('url', $loc, $t->updated_at, 'weekly', $t->is_featured ? '0.8' : '0.6', $images, $alternates);
            }
            // Its sevas with their fees, and its online hundi.
            if ($t->pujas_count > 0) {
                $xml .= self::entry('url', Seo::url('temples/'.$t->slug.'/sevas'), $t->updated_at, 'weekly', '0.5');
            }
            if ($t->accepts_donations && rescue(fn () => $t->canCollectPayments(), false)) {
                $xml .= self::entry('url', Seo::url('temples/'.$t->slug.'/donate'), $t->updated_at, 'monthly', '0.4');
            }
        }

        return self::xml($xml.'</urlset>');
    }

    protected static function open(bool $images = false, bool $languages = false): string
    {
        return self::declaration().'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"'
            .($images ? ' xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"' : '')
            .($languages ? ' xmlns:xhtml="http://www.w3.org/1999/xhtml"' : '').'>'."\n";
    }

    /**
     * @param  string|array<int, string>|null  $image
     * @param  array<string, string>  $alternates  hreflang => URL
     */
    protected static function entry(string $tag, string $loc, mixed $lastmod = null, ?string $freq = null, ?string $priority = null, string|array|null $image = null, array $alternates = []): string
    {
        $out = "  <{$tag}><loc>".self::esc($loc).'</loc>';
        if ($lastmod !== null) {
            $out .= '<lastmod>'.Carbon::parse($lastmod)->toAtomString().'</lastmod>';
        }
        if ($freq !== null) {
            $out .= "<changefreq>{$freq}</changefreq>";
        }
        if ($priority !== null) {
            $out .= "<priority>{$priority}</priority>";
        }
        foreach ((array) $image as $img) {
            $out .= '<image:image><image:loc>'.self::esc($img).'</image:loc></image:image>';
        }
        foreach ($alternates as $hreflang => $href) {
            $out .= '<xhtml:link rel="alternate" hreflang="'.self::esc($hreflang).'" href="'.self::esc($href).'"/>';
        }

        return $out."</{$tag}>\n";
    }

    /**
     * The XML declaration, and a stylesheet so a person opening the file
     * in a browser sees a table rather than run-together text. Search
     * engines read the XML and ignore the stylesheet.
     */
    protected static function declaration(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<?xml-stylesheet type="text/xsl" href="/sitemap.xsl"?>'."\n";
    }

    /** The stylesheet named above. */
    public function stylesheet(): Response
    {
        return response((string) file_get_contents(resource_path('sitemap.xsl')), 200, [
            'Content-Type' => 'text/xsl; charset=UTF-8',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    /**
     * Text made safe inside XML: escaped, and without the control
     * characters XML forbids outright (one makes the whole file unreadable).
     */
    protected static function esc(string $text): string
    {
        return htmlspecialchars((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    protected static function xml(string $body): Response
    {
        // Anything a server-side file printed before this (a blank line, a
        // byte-order mark) would land before "<?xml", which XML does not
        // allow: Google then cannot read the file at all. Drop it.
        while (ob_get_level() > 0 && ! app()->runningUnitTests()) {
            ob_end_clean();
        }

        return response($body, 200, ['Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => 'public, max-age=3600']);
    }
}
