<?php

namespace Tests\Feature\Http;

use App\Enums\TempleStatus;
use App\Models\Setting;
use App\Models\State;
use App\Models\Temple;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * darshansaathi.com/ is an HTML page like the rest of the site: search,
 * popular temples, states, deities and the way to the app. Links and
 * caches left by the old Flutter web app lead back to these pages.
 */
class WebsiteHomePageTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'https://darshansaathi.com';

    protected function setUp(): void
    {
        parent::setUp();
        config(['brand.website' => self::SITE]);
    }

    public function test_the_home_page_is_the_temple_directory_front_page(): void
    {
        $state = State::create(['name' => 'Telangana', 'slug' => 'telangana', 'code' => 'TG', 'type' => 'state']);
        Temple::create(['name' => 'Chilkur Balaji Temple', 'slug' => 'chilkur', 'city' => 'Hyderabad', 'state_id' => $state->id, 'is_featured' => true, 'status' => TempleStatus::Published, 'published_at' => now()]);
        Temple::create(['name' => 'Draft Temple', 'slug' => 'draft', 'status' => TempleStatus::Draft]);

        $this->get(self::SITE.'/')->assertOk()
            ->assertSee('<link rel="canonical" href="'.self::SITE.'/">', false)
            ->assertDontSee('noindex', false)
            ->assertSee('action="'.self::SITE.'/temples"', false)
            ->assertSee('Popular temples')
            ->assertSee('href="'.self::SITE.'/temples/chilkur"', false)
            ->assertSee('href="'.self::SITE.'/states/telangana"', false)
            ->assertSee('id="app"', false)
            ->assertDontSee('Draft Temple')
            ->assertDontSee('flutter_bootstrap.js', false);
    }

    public function test_get_the_app_goes_to_the_store_once_it_is_set(): void
    {
        Setting::set('app_android_store_url', 'https://play.google.com/store/apps/details?id=com.darshansaathi.templevisit');

        $this->get(self::SITE.'/')->assertOk()
            ->assertSee('href="https://play.google.com/store/apps/details?id=com.darshansaathi.templevisit"', false)
            ->assertSee('Get it on Google Play');
    }

    public function test_old_web_app_links_open_the_temple_page(): void
    {
        $this->get(self::SITE.'/?temple=chilkur')->assertRedirect(self::SITE.'/temples/chilkur')->assertStatus(301);
        $this->get(self::SITE.'/?temple=chilkur&action=book')->assertRedirect(self::SITE.'/temples/chilkur/sevas');
        $this->get(self::SITE.'/?temple=chilkur&action=donate')->assertRedirect(self::SITE.'/temples/chilkur/donate');
        $this->get(self::SITE.'/index.html')->assertRedirect('/')->assertStatus(301);
    }

    public function test_the_old_apps_service_worker_removes_itself(): void
    {
        $this->get(self::SITE.'/flutter_service_worker.js')->assertOk()
            ->assertHeader('Content-Type', 'text/javascript; charset=utf-8')
            ->assertSee('registration.unregister()', false);
    }

    public function test_the_website_is_open_to_search_engines(): void
    {
        $this->get(self::SITE.'/robots.txt')->assertOk()
            ->assertSee('Allow: /')
            ->assertSee('Sitemap: '.self::SITE.'/sitemap.xml')
            ->assertDontSee('Disallow: /admin');
    }
}
