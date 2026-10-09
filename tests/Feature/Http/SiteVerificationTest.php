<?php

namespace Tests\Feature\Http;

use App\Enums\TempleStatus;
use App\Enums\UserRole;
use App\Filament\Pages\Settings\ManageAnalytics;
use App\Models\Setting;
use App\Models\Temple;
use App\Models\User;
use App\Support\Seo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Search Console's tag, and any code pasted for every page, reach the home
 * page as well as the temple pages.
 */
class SiteVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['brand.website' => 'https://darshansaathi.com']);
    }

    public function test_the_whole_tag_or_just_its_code_can_be_pasted(): void
    {
        $this->assertSame('Rw4lRHdZkhGcPDaVxQ2EjgP0_UUctb_qQ4sGIdvgG7Y', Seo::verificationCode('<meta name="google-site-verification" content="Rw4lRHdZkhGcPDaVxQ2EjgP0_UUctb_qQ4sGIdvgG7Y" />'));
        $this->assertSame('Rw4lRHdZkhGcPDaVxQ2EjgP0_UUctb_qQ4sGIdvgG7Y', Seo::verificationCode('Rw4lRHdZkhGcPDaVxQ2EjgP0_UUctb_qQ4sGIdvgG7Y'));
        $this->assertNull(Seo::verificationCode('<script>alert(1)</script>'));
    }

    public function test_the_home_page_carries_the_tag_and_the_pasted_code(): void
    {
        Setting::set('google_site_verification', '<meta name="google-site-verification" content="Rw4lRHdZkhGcPDaVxQ2EjgP0_UUctb_qQ4sGIdvgG7Y" />');
        Setting::set('custom_head_html', '<script>window.gtmLoaded=true</script>');
        Setting::set('custom_body_html', '<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-TEST"></iframe></noscript>');

        $html = $this->get('https://darshansaathi.com/')->assertOk()->getContent();

        $this->assertStringContainsString('<meta name="google-site-verification" content="Rw4lRHdZkhGcPDaVxQ2EjgP0_UUctb_qQ4sGIdvgG7Y">', $html);
        $this->assertLessThan(strpos($html, '</head>'), strpos($html, 'google-site-verification'));
        $this->assertStringContainsString("<body>\n    <noscript><iframe src=\"https://www.googletagmanager.com/ns.html?id=GTM-TEST\">", $html);
        $this->assertStringContainsString('<script>window.gtmLoaded=true</script>', $html);

        // The same on the temple pages.
        Temple::create(['name' => 'Sri Rama Temple', 'slug' => 'sri-rama', 'status' => TempleStatus::Published, 'published_at' => now()]);
        $this->get('https://darshansaathi.com/temples/sri-rama')->assertOk()
            ->assertSee('<meta name="google-site-verification" content="Rw4lRHdZkhGcPDaVxQ2EjgP0_UUctb_qQ4sGIdvgG7Y">', false)
            ->assertSee('window.gtmLoaded=true', false)
            ->assertSee('GTM-TEST', false);
    }

    public function test_the_admin_host_keeps_its_own_landing_page(): void
    {
        $this->get('https://temple.darshansaathi.com/')->assertOk()->assertSee('Temple portal')->assertDontSee('Popular temples');
    }

    public function test_super_admins_save_the_tag_and_the_code_from_the_panel(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin, 'is_active' => true]));

        Livewire::test(ManageAnalytics::class)
            ->set('data.google_site_verification', '<meta name="google-site-verification" content="Rw4lRHdZkhGcPDaVxQ2EjgP0_UUctb_qQ4sGIdvgG7Y" />')
            ->set('data.custom_head_html', '<script>1</script>')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('<script>1</script>', Setting::get('custom_head_html'));
        $this->assertStringContainsString('Rw4lRHdZkhGcPDaVxQ2EjgP0_UUctb_qQ4sGIdvgG7Y', Setting::get('google_site_verification'));
    }
}
