<?php

namespace Tests\Feature\Http;

use App\Enums\TempleStatus;
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
            ->assertSee('<title>Sri Someshwara Swamy Temple, Kolanupaka, Telangana: timings, pujas, how to reach', false)
            ->assertSee('<link rel="canonical" href="https://darshansaathi.com/temples/someshwara-kolanupaka">', false)
            ->assertSee('"@type":"HinduTemple"', false)
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
}
