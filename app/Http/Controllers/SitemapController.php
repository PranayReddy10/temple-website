<?php

namespace App\Http\Controllers;

use App\Models\Page;
use App\Models\Temple;
use App\Support\Seo;
use Illuminate\Http\Response;

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

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
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
        }
        foreach (PublicTempleController::deitiesWithTemples() as $deity) {
            $xml .= self::entry('url', Seo::url('deities/'.$deity->slug), null, 'weekly', '0.7');
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
            ->with('primaryPhoto')
            ->get(['id', 'name', 'slug', 'updated_at', 'is_featured']);

        abort_if($temples->isEmpty() && $page > 1, 404);

        // With each temple's cover, so its photo can show in image search.
        $xml = self::open(images: true);
        foreach ($temples as $t) {
            $image = Seo::absolute($t->primaryPhoto?->mediumUrl());
            $xml .= self::entry('url', Seo::url('temples/'.$t->slug), $t->updated_at, 'weekly', $t->is_featured ? '0.8' : '0.6', $image);
        }

        return self::xml($xml.'</urlset>');
    }

    protected static function open(bool $images = false): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"'
            .($images ? ' xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"' : '').'>'."\n";
    }

    protected static function entry(string $tag, string $loc, mixed $lastmod = null, ?string $freq = null, ?string $priority = null, ?string $image = null): string
    {
        $out = "  <{$tag}><loc>".e($loc).'</loc>';
        if ($lastmod !== null) {
            $out .= '<lastmod>'.\Illuminate\Support\Carbon::parse($lastmod)->toAtomString().'</lastmod>';
        }
        if ($freq !== null) {
            $out .= "<changefreq>{$freq}</changefreq>";
        }
        if ($priority !== null) {
            $out .= "<priority>{$priority}</priority>";
        }
        if ($image !== null) {
            $out .= '<image:image><image:loc>'.e($image).'</image:loc></image:image>';
        }

        return $out."</{$tag}>\n";
    }

    protected static function xml(string $body): Response
    {
        return response($body, 200, ['Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => 'public, max-age=3600']);
    }
}
