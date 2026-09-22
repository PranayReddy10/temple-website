<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\TempleTheme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TempleThemeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_stylesheet_link_does_not_depend_on_app_url(): void
    {
        config(['app.url' => 'http://localhost']);

        $href = TempleTheme::stylesheetHref();

        // asset() would produce http://localhost/... here, which a browser on
        // the real domain cannot fetch and an https page blocks outright.
        $this->assertStringStartsWith('/css/temple-admin.css', $href);
        $this->assertStringNotContainsString('localhost', $href);
        $this->assertStringNotContainsString('http', $href);
    }

    public function test_the_link_carries_a_cache_busting_version(): void
    {
        // Without it a browser keeps the stylesheet it cached before the
        // deploy, which looks exactly like the CSS not having shipped.
        $this->assertMatchesRegularExpression('#\?v=\d+$#', TempleTheme::stylesheetHref());
    }

    public function test_the_stylesheet_exists_where_the_link_points(): void
    {
        $this->assertFileExists(public_path(TempleTheme::STYLESHEET));
    }

    public function test_both_panels_load_the_stylesheet(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin, 'is_active' => true]);
        $templeAdmin = User::factory()->create(['role' => UserRole::TempleAdmin, 'is_active' => true]);

        $this->actingAs($admin)->get('/admin')
            ->assertOk()
            ->assertSee('/css/temple-admin.css', escape: false);

        $this->actingAs($templeAdmin)->get('/temple')
            ->assertOk()
            ->assertSee('/css/temple-admin.css', escape: false);
    }

    public function test_the_login_pages_load_it_too(): void
    {
        // The sign-in screen is the first thing anyone sees.
        $this->get('/admin/login')->assertOk()->assertSee('/css/temple-admin.css', escape: false);
        $this->get('/temple/login')->assertOk()->assertSee('/css/temple-admin.css', escape: false);
    }

    public function test_the_stylesheet_defines_the_dashboard_panel_styles(): void
    {
        $css = file_get_contents(public_path(TempleTheme::STYLESHEET));

        // The widget markup is useless without these; the pairing is what
        // actually broke on the live site.
        foreach (['.temple-today', '.temple-today__mantra', '.temple-today__deity'] as $selector) {
            $this->assertStringContainsString($selector, $css);
        }
    }
}
