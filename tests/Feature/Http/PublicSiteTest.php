<?php

namespace Tests\Feature\Http;

use App\Enums\TempleStatus;
use App\Models\Deity;
use App\Models\Setting;
use App\Models\State;
use App\Models\Temple;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The indexable temple pages and the sitemap behind darshansaathi.com. */
class PublicSiteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['brand.website' => 'https://darshansaathi.com']);
    }

    protected function temple(array $attrs = []): Temple
    {
        $state = State::firstOrCreate(['slug' => 'telangana'], ['name' => 'Telangana', 'code' => 'TG', 'type' => 'state']);

        return Temple::create([
            'name' => 'Sri Someshwara Swamy Temple', 'slug' => 'someshwara-kolanupaka', 'city' => 'Kolanupaka',
            'state_id' => $state->id, 'latitude' => 17.6, 'longitude' => 79.0, 'short_description' => 'An ancient Shiva temple on the Aler road.',
            'status' => TempleStatus::Published, 'published_at' => now(), ...$attrs,
        ]);
    }

    public function test_a_temple_page_is_indexable_on_the_website_with_its_structured_data(): void
    {
        $this->temple();

        $this->get('https://darshansaathi.com/temples/someshwara-kolanupaka')->assertOk()
            ->assertSee('<title>Sri Someshwara Swamy Temple, Kolanupaka: Timings &amp; How to Reach', false)
            ->assertSee('<link rel="canonical" href="https://darshansaathi.com/temples/someshwara-kolanupaka">', false)
            ->assertSee('"@type":["HinduTemple","TouristAttraction"]', false)
            ->assertSee('An ancient Shiva temple on the Aler road.')
            ->assertDontSee('noindex');
    }

    public function test_the_admin_hosts_copy_points_at_the_website_and_is_not_indexed(): void
    {
        $this->temple();

        $this->get('https://temple.darshansaathi.com/temples/someshwara-kolanupaka')->assertOk()
            ->assertSee('<link rel="canonical" href="https://darshansaathi.com/temples/someshwara-kolanupaka">', false)
            ->assertSee('<meta name="robots" content="noindex, follow">', false);
    }

    public function test_drafts_are_not_public_and_the_directory_lists_by_state(): void
    {
        $this->temple();
        $this->temple(['name' => 'Draft Temple', 'slug' => 'draft-temple', 'status' => TempleStatus::Draft, 'published_at' => null]);

        $this->get('https://darshansaathi.com/temples/draft-temple')->assertNotFound();
        $this->get('https://darshansaathi.com/temples')->assertOk()
            ->assertSee('Sri Someshwara Swamy Temple')->assertDontSee('Draft Temple')
            ->assertSee('https://darshansaathi.com/states/telangana', false);
        $this->get('https://darshansaathi.com/states/telangana')->assertOk()->assertSee('Temples in Telangana');
        $this->get('https://darshansaathi.com/states/nowhere')->assertNotFound();
    }

    public function test_the_sitemap_lists_published_temples_on_the_website(): void
    {
        $this->temple();
        $this->temple(['name' => 'Draft Temple', 'slug' => 'draft-temple', 'status' => TempleStatus::Draft, 'published_at' => null]);

        $this->get('/sitemap.xml')->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->assertSee('<loc>https://darshansaathi.com/sitemap-pages.xml</loc>', false)
            ->assertSee('<loc>https://darshansaathi.com/sitemap-temples-1.xml</loc>', false);

        $this->get('/sitemap-pages.xml')->assertOk()
            ->assertSee('<loc>https://darshansaathi.com/temples</loc>', false)
            ->assertSee('<loc>https://darshansaathi.com/states/telangana</loc>', false);

        $this->get('/sitemap-temples-1.xml')->assertOk()
            ->assertSee('<loc>https://darshansaathi.com/temples/someshwara-kolanupaka</loc>', false)
            ->assertDontSee('draft-temple');
        $this->get('/sitemap-temples-2.xml')->assertNotFound();
    }

    public function test_deity_pages_list_their_temples_and_temple_pages_link_on(): void
    {
        $shiva = Deity::create(['name' => 'Lord Shiva', 'slug' => 'shiva', 'description' => 'The auspicious one.']);
        $this->temple(['deity_id' => $shiva->id]);
        // Far apart, so they are listed by deity and state rather than as nearby.
        $this->temple(['name' => 'Ramappa Temple', 'slug' => 'ramappa', 'city' => 'Palampet', 'deity_id' => $shiva->id, 'latitude' => 18.26, 'longitude' => 79.94]);
        $this->temple(['name' => 'Yadadri Temple', 'slug' => 'yadadri', 'city' => 'Yadagirigutta', 'latitude' => 16.6, 'longitude' => 78.0]);
        Deity::create(['name' => 'Ganesha', 'slug' => 'ganesha']);

        $this->get('https://darshansaathi.com/deities/shiva')->assertOk()
            ->assertSee('<title>Shiva temples in India: timings, pujas and how to reach', false)
            ->assertSee('Ramappa Temple')->assertDontSee('Yadadri Temple')
            ->assertSee('The auspicious one.');
        // No temples yet: no thin page for search engines.
        $this->get('https://darshansaathi.com/deities/ganesha')->assertNotFound();

        $this->get('https://darshansaathi.com/temples/someshwara-kolanupaka')->assertOk()
            ->assertSee('More Shiva temples')
            ->assertSee('https://darshansaathi.com/temples/ramappa', false)
            ->assertSee('More temples in Telangana')
            ->assertSee('https://darshansaathi.com/temples/yadadri', false)
            ->assertSee('https://darshansaathi.com/deities/shiva', false);

        $this->get('/sitemap-pages.xml')->assertSee('<loc>https://darshansaathi.com/deities/shiva</loc>', false)
            ->assertDontSee('deities/ganesha');
    }

    public function test_opening_hours_are_structured_data(): void
    {
        $temple = $this->temple();
        $temple->timings()->create(['kind' => 'darshan', 'opens_at' => '05:30', 'closes_at' => '12:00']);

        $this->get('https://darshansaathi.com/temples/someshwara-kolanupaka')
            ->assertSee('"openingHoursSpecification":[{"@type":"OpeningHoursSpecification","dayOfWeek":["Monday"', false)
            ->assertSee('"opens":"05:30","closes":"12:00"', false);
    }

    public function test_search_console_verification_and_analytics_come_from_the_admin_panel(): void
    {
        $this->temple();
        $page = 'https://darshansaathi.com/temples/someshwara-kolanupaka';

        $this->get('https://darshansaathi.com/google0123abcd.html')->assertNotFound();
        $this->get($page)->assertDontSee('google-site-verification')->assertDontSee('googletagmanager');

        Setting::set('google_site_verification_file', 'google0123abcd.html');
        Setting::set('google_site_verification', 'tok-123');
        Setting::set('firebase_measurement_id', 'G-ABC123');

        $this->get('https://darshansaathi.com/google0123abcd.html')->assertOk()->assertSeeText('google-site-verification: google0123abcd.html');
        $this->get('https://darshansaathi.com/google9999.html')->assertNotFound();
        $this->get($page)->assertSee('<meta name="google-site-verification" content="tok-123">', false)
            ->assertDontSee('googletagmanager');

        Setting::set('analytics_enabled', '1', 'boolean');
        $this->get($page)->assertSee('googletagmanager.com/gtag/js?id=G-ABC123', false);
        // Not on the admin host's unindexed copy.
        $this->get('https://temple.darshansaathi.com/temples/someshwara-kolanupaka')->assertDontSee('googletagmanager');
    }

    public function test_the_temple_sitemap_carries_cover_photos(): void
    {
        $this->temple();

        $this->get('/sitemap-temples-1.xml')->assertOk()
            ->assertSee('xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"', false);
    }

    public function test_a_mistyped_temple_address_goes_to_the_temple_it_clearly_means(): void
    {
        $this->temple(['name' => 'Sri Lakshmi Narasimha Swamy Temple, Mattapalli', 'slug' => 'sri-lakshmi-narasimha-swamy-temple-mattapalli', 'city' => 'Mattapalli']);
        $this->temple(['name' => 'Lakshmi Narasimha Swamy Temple, Yadagirigutta', 'slug' => 'lakshmi-narasimha-swamy-yadadri', 'city' => 'Yadagirigutta']);

        $this->get('https://darshansaathi.com/temples/mattapalli-lakshmi-narasimha-swamy-temple')
            ->assertStatus(301)
            ->assertRedirect('https://darshansaathi.com/temples/sri-lakshmi-narasimha-swamy-temple-mattapalli');

        // Two equally likely temples: the 404 page offers both instead.
        $this->get('https://darshansaathi.com/temples/lakshmi-narasimha-temple')->assertNotFound()
            ->assertSee('The doors to this page are closed')
            ->assertSee('Were you looking for')
            ->assertSee('https://darshansaathi.com/temples/sri-lakshmi-narasimha-swamy-temple-mattapalli', false)
            ->assertSee('https://darshansaathi.com/temples/lakshmi-narasimha-swamy-yadadri', false)
            ->assertSee('<meta name="robots" content="noindex, follow">', false);

        // A draft is never found this way.
        $this->temple(['name' => 'Hidden Draft Temple', 'slug' => 'hidden-draft-temple', 'status' => TempleStatus::Draft, 'published_at' => null]);
        $this->get('https://darshansaathi.com/temples/hidden-draft')->assertNotFound()->assertDontSee('Hidden Draft Temple');
    }

    public function test_the_directory_searches_and_any_missing_page_gets_the_temple_404(): void
    {
        $this->temple();
        $this->temple(['name' => 'Ramappa Temple', 'slug' => 'ramappa', 'city' => 'Palampet']);

        $this->get('https://darshansaathi.com/temples?q=palampet')->assertOk()
            ->assertSee('Temples matching')->assertSee('Ramappa Temple')->assertDontSee('Sri Someshwara Swamy Temple')
            ->assertSee('noindex', false);

        $this->get('https://darshansaathi.com/no-such-page')->assertNotFound()
            ->assertSee('The doors to this page are closed')
            ->assertSee('href="https://darshansaathi.com/temples"', false);
    }
}
