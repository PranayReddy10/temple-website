<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Setting;
use App\Models\User;
use App\Support\Pwa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Installing the panels on a phone's home screen.
 *
 * Most of what is tested here is iOS-shaped. Safari offers no install prompt,
 * so nothing here can be discovered by a user poking at the panel — if a tag
 * is missing or an icon 404s, the app simply cannot be installed and there is
 * no error anywhere to say why.
 */
class PwaTest extends TestCase
{
    use RefreshDatabase;

    protected function staff(): User
    {
        return User::factory()->create(['role' => UserRole::SuperAdmin, 'is_active' => true]);
    }

    // --- The manifest ---

    public function test_each_panel_has_its_own_manifest(): void
    {
        foreach (['admin', 'temple'] as $panel) {
            $this->get('/manifest/'.$panel.'.webmanifest')
                ->assertOk()
                ->assertHeader('Content-Type', 'application/manifest+json');
        }
    }

    public function test_an_unknown_panel_has_none(): void
    {
        $this->get('/manifest/devotee.webmanifest')->assertNotFound();
    }

    /**
     * Two manifests on one origin with the same identity are treated as the
     * same app: installing the temple portal would replace the admin panel on
     * the home screen rather than sit beside it.
     */
    public function test_the_two_panels_are_different_apps(): void
    {
        $admin = $this->get('/manifest/admin.webmanifest')->json();
        $temple = $this->get('/manifest/temple.webmanifest')->json();

        $this->assertNotSame($admin['id'], $temple['id']);
        $this->assertNotSame($admin['start_url'], $temple['start_url']);
        $this->assertNotSame($admin['name'], $temple['name']);
    }

    public function test_each_manifest_opens_its_own_panel(): void
    {
        $this->assertSame('/admin', $this->get('/manifest/admin.webmanifest')->json('start_url'));
        $this->assertSame('/temple', $this->get('/manifest/temple.webmanifest')->json('start_url'));
    }

    /**
     * Scoped to the whole site, not to the panel's own path.
     *
     * A link outside the scope opens in the browser instead of in the app, and
     * the panels link to each other and to uploaded files. On iOS that browser
     * is a separate session, so being thrown out looks like being signed out.
     */
    public function test_the_scope_covers_the_whole_site(): void
    {
        $this->assertSame('/', $this->get('/manifest/admin.webmanifest')->json('scope'));
    }

    public function test_it_asks_to_open_without_browser_chrome(): void
    {
        $this->assertSame('standalone', $this->get('/manifest/admin.webmanifest')->json('display'));
    }

    /** Renaming the product renames the app, rather than leaving the old name. */
    public function test_the_name_follows_the_brand_setting(): void
    {
        Setting::set('brand_name', 'Yatra Sathi');

        $this->assertStringContainsString(
            'Yatra Sathi',
            $this->get('/manifest/admin.webmanifest')->json('name'),
        );
    }

    /**
     * Every icon the manifest names has to exist. A manifest listing a missing
     * icon is refused outright by some browsers — no install, no message.
     */
    public function test_every_icon_it_names_is_really_there(): void
    {
        foreach (['admin', 'temple'] as $panel) {
            foreach ($this->get('/manifest/'.$panel.'.webmanifest')->json('icons') as $icon) {
                $this->assertFileExists(
                    public_path(ltrim($icon['src'], '/')),
                    $icon['src'].' is named by the '.$panel.' manifest',
                );
            }
        }
    }

    /**
     * A maskable icon is padded so a launcher may crop it. Declaring one file
     * both "any" and "maskable" is the common mistake: it renders small and
     * floating everywhere that does not crop, which is most places.
     */
    public function test_the_maskable_icon_is_a_separate_file(): void
    {
        $icons = collect($this->get('/manifest/admin.webmanifest')->json('icons'));

        $maskable = $icons->firstWhere('purpose', 'maskable');
        $any = $icons->where('purpose', 'any');

        $this->assertNotNull($maskable);
        $this->assertNotContains($maskable['src'], $any->pluck('src')->all());
    }

    // --- What the panel's head carries ---

    public function test_both_panels_link_their_manifest(): void
    {
        // Not signed in: the tags have to be on the login page too, because
        // that is the page somebody is looking at when they decide to install.
        $this->get('/admin/login')->assertSee('/manifest/admin.webmanifest', escape: false);
        $this->get('/temple/login')->assertSee('/manifest/temple.webmanifest', escape: false);
    }

    /**
     * The bug this caught, kept caught.
     *
     * Each panel used to register its own head hook. Filament resolves
     * panel-scoped hooks against whichever panel booted first, so the second
     * panel rendered in a process got the first one's tags — the temple portal
     * served the admin panel's manifest, and installing it put the wrong app
     * on somebody's home screen. Under PHP-FPM every request is its own
     * process, so both panels looked right and would have stayed right until
     * the day anything kept a process alive between requests.
     *
     * The two requests below, in this order, are the whole test.
     */
    public function test_a_panel_renders_its_own_manifest_even_after_the_other_one(): void
    {
        $this->get('/admin/login')->assertSee('/manifest/admin.webmanifest', escape: false);

        $this->get('/temple/login')
            ->assertSee('/manifest/temple.webmanifest', escape: false)
            ->assertDontSee('/manifest/admin.webmanifest', escape: false);

        $this->get('/admin/login')
            ->assertSee('/manifest/admin.webmanifest', escape: false)
            ->assertDontSee('/manifest/temple.webmanifest', escape: false);
    }

    /**
     * iOS reads these, not the manifest. Safari only began honouring the
     * manifest's display mode in 16.4, and apple-mobile-web-app-capable is
     * what every version before that goes by — without it the panel opens in
     * a Safari window with its chrome, which is what installing was meant to
     * avoid.
     */
    public function test_the_head_carries_what_ios_reads(): void
    {
        $page = $this->get('/admin/login');

        $page->assertSee('apple-mobile-web-app-capable', escape: false);
        $page->assertSee('apple-mobile-web-app-title', escape: false);
        $page->assertSee('apple-touch-icon', escape: false);
    }

    /**
     * "black-translucent" puts the page under the status bar, which on a
     * notched iPhone hides the top of Filament's topbar.
     */
    public function test_the_status_bar_is_left_as_its_own_strip(): void
    {
        $this->get('/admin/login')
            ->assertSee('name="apple-mobile-web-app-status-bar-style" content="default"', escape: false);
    }

    /** The label under the icon, which iOS truncates at about twelve characters. */
    public function test_the_home_screen_label_is_short(): void
    {
        foreach (Pwa::PANELS as $definition) {
            $this->assertLessThanOrEqual(15, strlen($definition['short']), $definition['short']);
        }
    }

    /**
     * Root-relative, never asset(). asset() builds absolute URLs from APP_URL,
     * which is http://localhost until somebody changes it — and an https page
     * linking a manifest at localhost gets a manifest it cannot fetch and an
     * app that cannot be installed, with nothing in the log.
     */
    public function test_nothing_in_the_head_depends_on_app_url(): void
    {
        config(['app.url' => 'http://localhost']);

        $tags = Pwa::headTags('admin');

        $this->assertStringNotContainsString('localhost', $tags);
        $this->assertStringContainsString('href="/manifest/admin.webmanifest"', $tags);
    }

    public function test_a_panel_that_cannot_be_installed_gets_no_tags(): void
    {
        $this->assertSame('', Pwa::headTags('devotee'));
    }

    // --- The service worker ---

    public function test_the_worker_is_served_from_the_site_root(): void
    {
        // A worker may only control pages at or below its own path, so one
        // below /admin could never control /temple, and one at /pwa/sw.js
        // could control neither.
        $this->get('/sw.js')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/javascript');
    }

    /**
     * The one file that cannot fix itself if it goes stale: the browser would
     * keep asking an old worker whether there is a new worker.
     */
    public function test_the_worker_is_never_cached_by_the_browser(): void
    {
        $this->assertStringContainsString(
            'no-store',
            $this->get('/sw.js')->headers->get('Cache-Control'),
        );
    }

    public function test_the_worker_carries_a_version_that_moves_with_a_deploy(): void
    {
        $before = Pwa::assetVersion();

        touch(public_path('css/temple-admin.css'), time() + 60);

        $this->assertNotSame($before, Pwa::assetVersion());
    }

    /**
     * The whole design of the worker in one assertion.
     *
     * Caching a Filament document would store a CSRF token, a Livewire
     * snapshot and whatever that one account may see — wrong the moment it is
     * stored, and wrong in a way that reads as a bug in the panel rather than
     * a bug in the cache. So navigations go to the network, always, and the
     * cache is only ever consulted when the network is gone.
     */
    public function test_the_worker_never_serves_a_page_from_its_cache(): void
    {
        $worker = $this->get('/sw.js')->getContent();

        // Navigations: fetched, with the cache reached for only on failure.
        $this->assertMatchesRegularExpression(
            '/request\.mode === .navigate./',
            $worker,
        );
        $this->assertStringContainsString('fetch(request).catch(', $worker);

        // And nothing at all happens to a POST.
        $this->assertStringContainsString("request.method !== 'GET'", $worker);
    }

    public function test_the_offline_page_stands_on_its_own(): void
    {
        $page = $this->get('/offline')->assertOk()->getContent();

        // It is rendered when the network is gone, so anything it asks for is
        // something it will not get.
        $this->assertStringNotContainsString('temple-admin.css', $page);
        $this->assertStringNotContainsString('fonts.googleapis', $page);
        $this->assertStringContainsString('<style>', $page);
    }

    public function test_the_offline_page_needs_no_sign_in(): void
    {
        // It is shown to whoever had the panel open, and an auth redirect
        // needs the network that has just gone.
        $this->get('/offline')->assertOk();
    }
}
