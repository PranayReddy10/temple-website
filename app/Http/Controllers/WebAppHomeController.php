<?php

namespace App\Http\Controllers;

use App\Models\Temple;
use App\Support\Seo;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * darshansaathi.com/ — the web app's own index.html, with the verification
 * tags and the code pasted under Analytics & SEO added.
 *
 * The home page is the Flutter build, a static file nobody can edit from the
 * admin panel, and it is the page Search Console checks for its tag. So the
 * website's .htaccess hands "/" here, and this serves that same file with
 * the additions. If the build cannot be found, it says so rather than
 * showing a blank page.
 */
class WebAppHomeController extends Controller
{
    public function __invoke(): Response
    {
        $path = self::indexPath();
        $html = $path !== null ? (string) file_get_contents($path) : null;

        if ($html === null || $html === '') {
            return response('The website is being updated. Please try again in a minute.', 503, ['Retry-After' => '60']);
        }

        return response(self::withExtras(self::withDirectoryLinks($html)), 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            // The app's new version must reach people at once.
            'Cache-Control' => 'no-cache',
        ]);
    }

    /** The build's index.html: as configured, else the website's own document root. */
    public static function indexPath(): ?string
    {
        $candidates = array_filter([
            (string) config('brand.web_app_index'),
            isset($_SERVER['DOCUMENT_ROOT']) ? rtrim((string) $_SERVER['DOCUMENT_ROOT'], '/').'/index.html' : null,
            base_path('../index.html'),
        ]);

        foreach ($candidates as $path) {
            // Never this application's own public/index.php by mistake.
            if (is_file($path) && str_ends_with($path, 'index.html')) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Links into the temple directory, inside the page's search-engine text
     * (<main id="seo">): the states, deities and best-known temples.
     *
     * The home page is the site's strongest page, but the app draws on a
     * canvas that crawlers cannot follow; without these, the only way from it
     * to a temple was one "browse all temples" link.
     */
    public static function withDirectoryLinks(string $html): string
    {
        if (! preg_match('#<main[^>]*id="seo"[^>]*>#i', $html, $m, PREG_OFFSET_CAPTURE)) {
            return $html;
        }

        $close = stripos($html, '</main>', $m[0][1]);
        if ($close === false) {
            return $html;
        }

        $links = Cache::remember('home:directory-links', now()->addHour(), function (): string {
            $section = function (string $heading, iterable $items): string {
                $out = '';
                foreach ($items as [$href, $label]) {
                    $out .= '<li><a href="'.e($href).'">'.e($label).'</a></li>';
                }

                return $out === '' ? '' : '<h2>'.e($heading).'</h2><ul>'.$out.'</ul>';
            };

            $temples = Temple::query()->published()
                ->orderByDesc('is_featured')->orderByDesc('published_at')
                ->limit(60)->get(['id', 'name', 'slug', 'city']);

            return $section('Popular temples', $temples->map(fn (Temple $t): array => [
                Seo::url('temples/'.$t->slug), $t->name.($t->city ? ', '.$t->city : ''),
            ]))
                .$section('Temples by state', PublicTempleController::statesWithTemples()->map(fn ($s): array => [
                    Seo::url('states/'.$s->slug), 'Temples in '.$s->name.' ('.$s->temples_count.')',
                ]))
                .$section('Temples by deity', PublicTempleController::deitiesWithTemples()->take(20)->map(fn ($d): array => [
                    Seo::url('deities/'.$d->slug), PublicTempleController::deityPhrase($d->name).' temples',
                ]));
        });

        return substr($html, 0, $close).$links.substr($html, $close);
    }

    public static function withExtras(string $html): string
    {
        $head = Seo::headExtras();
        $body = Seo::bodyExtras();

        if ($head !== '') {
            $html = preg_replace('#</head>#i', $head."\n</head>", $html, 1) ?? $html;
        }
        if ($body !== '') {
            $html = preg_replace('#(<body[^>]*>)#i', '$1'."\n".str_replace(['\\', '$'], ['\\\\', '\\$'], $body), $html, 1) ?? $html;
        }

        return $html;
    }
}
