<?php

namespace App\Http\Controllers;

use App\Support\Seo;
use Illuminate\Http\Response;

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

        return response(self::withExtras($html), 200, [
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
