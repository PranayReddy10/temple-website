<?php

namespace App\Support;

use Illuminate\Support\Facades\File;

/**
 * The admin panels, installed on a phone's home screen.
 *
 * Both panels are run from a phone more than anyone plans for — a temple's own
 * team checking tomorrow's events on the way home, an editor approving a photo
 * on a train. In a browser tab that means finding the tab, finding the
 * bookmark, and losing a third of a small screen to browser chrome.
 *
 * Installed, they open from an icon, full height, and stay signed in.
 *
 * iOS is the reason for most of what is here. Safari ignores the install
 * prompt every other browser offers, so there is nothing to trigger and no
 * event to listen for: somebody has to know to tap Share → Add to Home Screen,
 * which is why the profile page says so. It also predates parts of the web
 * manifest, so the apple-* meta tags below are not redundant with it — they
 * are what iOS actually reads for the title, the status bar and the icon.
 */
final class Pwa
{
    /**
     * Which panels can be installed, and how each one presents itself.
     *
     * Two separate installs rather than one, because they are two different
     * jobs done by two different people. A temple's team should get their own
     * portal from their own icon, not a shortcut into the editorial panel that
     * answers 403.
     */
    public const PANELS = [
        'admin' => [
            'path' => '/admin',
            'suffix' => 'Admin',
            'short' => 'Temple Admin',
            'description' => 'Temples, photos, pujas and the daily deity — the editorial panel.',
            'colour' => 'saffron',
        ],
        'temple' => [
            'path' => '/temple',
            'suffix' => '— Temple Portal',
            'short' => 'Temple Portal',
            'description' => 'Your temple\'s own timings, events, pujas and photos.',
            'colour' => 'kumkum',
        ],
    ];

    public static function isInstallable(string $panel): bool
    {
        return array_key_exists($panel, self::PANELS);
    }

    /**
     * The manifest for one panel.
     *
     * The name comes from the brand setting, so renaming the product in the
     * settings screen renames the app on somebody's home screen at their next
     * install — rather than leaving the old name on the icon forever, which is
     * what a hard-coded string here would do.
     *
     * @return array<string, mixed>
     */
    public static function manifest(string $panel): array
    {
        $definition = self::PANELS[$panel];
        $brand = (string) setting('brand_name', 'brand.name');

        return [
            // Distinct per panel, and stable. Two manifests on one origin with
            // the same identity are treated as the same app: installing the
            // second would replace the first.
            'id' => $definition['path'],
            'name' => $brand.' '.$definition['suffix'],
            'short_name' => $definition['short'],
            'description' => $definition['description'],
            'start_url' => $definition['path'],
            /*
             * The whole site, not just this panel's path.
             *
             * A link that leaves the scope opens in the browser instead, and
             * the panels link to each other and to uploaded files. Scoping to
             * /admin would mean opening a temple photo threw you out of the
             * app and into Safari, which on iOS is a separate session and
             * looks like being signed out.
             */
            'scope' => '/',
            'display' => 'standalone',
            'orientation' => 'any',
            'background_color' => config('brand.colors.surface.hex', '#FFFDF9'),
            'theme_color' => config('brand.colors.'.$definition['colour'].'.hex'),
            'lang' => (string) setting('default_locale', 'app.locale', 'en'),
            'dir' => 'ltr',
            'icons' => [
                [
                    'src' => '/icons/icon-192.png',
                    'sizes' => '192x192',
                    'type' => 'image/png',
                    'purpose' => 'any',
                ],
                [
                    'src' => '/icons/icon-512.png',
                    'sizes' => '512x512',
                    'type' => 'image/png',
                    'purpose' => 'any',
                ],
                /*
                 * Padded so a launcher may crop it to a circle or a squircle
                 * without taking the kalasha off the top. Declared separately
                 * rather than as "any maskable" on one file: an icon padded
                 * for the crop renders small and floating everywhere that does
                 * not crop, which is most places.
                 */
                [
                    'src' => '/icons/icon-maskable-512.png',
                    'sizes' => '512x512',
                    'type' => 'image/png',
                    'purpose' => 'maskable',
                ],
            ],
        ];
    }

    /**
     * The head tags for a panel: manifest, iOS's own set, and the registration.
     *
     * Root-relative throughout, never asset(). asset() builds absolute URLs
     * from APP_URL, which is http://localhost until somebody remembers to
     * change it — and an https page linking a manifest at localhost gets a
     * manifest that cannot be fetched and an app that cannot be installed,
     * with nothing in the server log because the request never arrives. The
     * same reasoning as App\Support\TempleTheme.
     */
    public static function headTags(string $panel): string
    {
        if (! self::isInstallable($panel)) {
            return '';
        }

        $definition = self::PANELS[$panel];
        $theme = e(config('brand.colors.'.$definition['colour'].'.hex'));
        // The label under the icon on an iPhone, which iOS truncates at about
        // twelve characters — so the short name, not the full one.
        $title = e($definition['short']);

        return implode("\n", [
            '<link rel="manifest" href="/manifest/'.e($panel).'.webmanifest">',
            '<meta name="theme-color" content="'.$theme.'">',

            // iOS reads these, not the manifest, for the home-screen title and
            // the icon. Safari only began honouring the manifest's display
            // mode in 16.4, and apple-mobile-web-app-capable is what every
            // version before that goes by.
            '<meta name="apple-mobile-web-app-capable" content="yes">',
            '<meta name="mobile-web-app-capable" content="yes">',

            // "default" keeps the status bar as its own strip above the page.
            // "black-translucent" puts the page underneath it, which on a
            // notched phone hides the top of Filament's topbar.
            '<meta name="apple-mobile-web-app-status-bar-style" content="default">',
            '<meta name="apple-mobile-web-app-title" content="'.$title.'">',
            '<link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">',
            '<link rel="icon" type="image/png" sizes="32x32" href="/icons/favicon-32.png">',

            self::registrationScript(),
        ]);
    }

    /**
     * Registers the worker, quietly.
     *
     * Deliberately after load: a service worker install competes with the page
     * for bandwidth, and the panel being usable matters more than it being
     * cached. A failure is swallowed — an admin panel that works online must
     * not show an error because an offline nicety did not install, and on
     * plain http (a local machine) registration is refused by design.
     */
    protected static function registrationScript(): string
    {
        return <<<'HTML'
            <script>
                if ('serviceWorker' in navigator) {
                    window.addEventListener('load', function () {
                        navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(function () {});
                    });
                }
            </script>
            HTML;
    }

    /**
     * A short stamp that changes when the deployed assets change.
     *
     * The cache name is built from this, so a deploy retires the old cache
     * instead of leaving somebody's installed panel serving last week's
     * stylesheet — the failure that gives service workers their reputation.
     */
    public static function assetVersion(): string
    {
        $watched = [
            public_path(TempleTheme::STYLESHEET),
            public_path('icons/icon-512.png'),
            public_path('css/filament/filament/app.css'),
        ];

        $stamps = array_map(
            fn (string $path): int => File::exists($path) ? File::lastModified($path) : 0,
            $watched,
        );

        return substr(sha1(implode('-', $stamps)), 0, 12);
    }
}
