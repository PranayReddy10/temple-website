<?php

namespace Tests\Feature\Http;

use App\Enums\TempleStatus;
use App\Enums\UserRole;
use App\Filament\Pages\Settings\ManageAds;
use App\Models\Devotee;
use App\Models\User;
use Livewire\Livewire;
use App\Models\Setting;
use App\Models\Temple;
use App\Support\WebAds;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** AdSense on the website: on reading pages only, never on account or payment pages. */
class WebsiteAdsTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'https://darshansaathi.com';

    private const SCRIPT = 'pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-1234567890123456';

    protected function setUp(): void
    {
        parent::setUp();
        config(['brand.website' => self::SITE]);
        Temple::create(['name' => 'Chilkur Balaji Temple', 'slug' => 'chilkur', 'status' => TempleStatus::Published, 'published_at' => now()]);
    }

    private function switchOn(): void
    {
        Setting::set('ads_web_enabled', '1', 'boolean');
        Setting::set('adsense_client', 'pub-1234567890123456');
        Setting::set('adsense_slot_temple', '9876543210');
    }

    public function test_no_ads_until_switched_on_with_a_publisher_id(): void
    {
        $this->get(self::SITE.'/temples/chilkur')->assertOk()->assertDontSee('adsbygoogle', false);
        Setting::set('ads_web_enabled', '1', 'boolean');
        $this->get(self::SITE.'/temples/chilkur')->assertDontSee('adsbygoogle', false);
    }

    public function test_reading_pages_carry_ads_and_private_pages_never(): void
    {
        $this->switchOn();

        $this->get(self::SITE.'/')->assertOk()->assertSee(self::SCRIPT, false);
        $this->get(self::SITE.'/temples')->assertOk()->assertSee(self::SCRIPT, false);
        $this->get(self::SITE.'/temples/chilkur')->assertOk()->assertSee(self::SCRIPT, false)
            ->assertSee('data-ad-slot="9876543210"', false)->assertSee('Advertisement');

        $this->get(self::SITE.'/login')->assertOk()->assertDontSee('adsbygoogle', false);
        $this->actingAs(Devotee::factory()->create(), 'devotee_web');
        $this->get(self::SITE.'/account')->assertOk()->assertDontSee('adsbygoogle', false);
        $this->get(self::SITE.'/account/bookings')->assertOk()->assertDontSee('adsbygoogle', false);

        // The admin host's copy of a temple page: never.
        $this->get('https://temple.darshansaathi.com/temples/chilkur')->assertOk()->assertDontSee('adsbygoogle', false);
    }

    public function test_ads_txt_lists_the_publisher(): void
    {
        $this->switchOn();
        Setting::set('ads_txt_extra', "facebook.com, 123, RESELLER, c3e20eee3f780d68\n");

        $this->get(self::SITE.'/ads.txt')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=utf-8')
            ->assertSee('google.com, pub-1234567890123456, DIRECT, f08c47fec0942fa0')
            ->assertSee('facebook.com, 123, RESELLER');
        $this->get(self::SITE.'/app-ads.txt')->assertOk()->assertSee('pub-1234567890123456');

        Setting::set('adsense_client', 'not-an-id');
        $this->assertNull(WebAds::client());
    }

    public function test_super_admins_set_website_ads_in_the_admin(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin, 'is_active' => true]));

        Livewire::test(ManageAds::class)
            ->set('data.ads_web_enabled', true)
            ->set('data.adsense_client', 'ca-pub-1234567890123456')
            ->set('data.adsense_slot_list', '1122334455')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('ca-pub-1234567890123456', WebAds::client());
        $this->assertSame('1122334455', WebAds::slot('list'));

        Livewire::test(ManageAds::class)->set('data.adsense_client', 'my-adsense')->call('save')->assertHasErrors(['data.adsense_client']);
    }
}
