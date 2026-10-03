<?php

namespace Tests\Feature\Http;

use App\Enums\TempleStatus;
use App\Models\Setting;
use App\Models\State;
use App\Models\Temple;
use App\Models\TemplePuja;
use App\Models\TempleTiming;
use App\Support\IndexNow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A temple's page is worth landing on from a search or a shared link, and
 * every way out of it leads to that same temple in the app.
 */
class TempleSharingSeoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['brand.website' => 'https://darshansaathi.com', 'brand.name' => 'Darshan Saathi']);
    }

    protected function temple(): Temple
    {
        $state = State::firstOrCreate(['slug' => 'telangana'], ['name' => 'Telangana', 'code' => 'TG', 'type' => 'state']);
        $temple = Temple::create([
            'name' => 'Sri Rama Temple', 'slug' => 'sri-rama-bhadrachalam', 'city' => 'Bhadrachalam', 'state_id' => $state->id,
            'latitude' => 17.67, 'longitude' => 80.89, 'short_description' => 'The temple of Sita Ramachandra on the Godavari.',
            'dress_code' => 'Traditional dress.', 'contact_phone' => '08743 232428',
            'status' => TempleStatus::Published, 'published_at' => now(),
        ]);
        TempleTiming::create(['temple_id' => $temple->id, 'kind' => 'darshan', 'opens_at' => '05:00', 'closes_at' => '21:00']);
        TemplePuja::create(['temple_id' => $temple->id, 'name' => 'Abhishekam', 'is_free' => true, 'app_booking_enabled' => true, 'is_published' => true]);

        return $temple;
    }

    public function test_the_page_opens_this_temple_in_the_app_and_answers_questions(): void
    {
        $this->temple();

        $this->get('https://darshansaathi.com/temples/sri-rama-bhadrachalam')->assertOk()
            // Book and open: this temple, not the app's home.
            ->assertSee('href="https://darshansaathi.com/?temple=sri-rama-bhadrachalam&amp;action=book"', false)
            ->assertSee('href="https://darshansaathi.com/?temple=sri-rama-bhadrachalam"', false)
            ->assertSee('Book a seva')
            ->assertSee('id="share"', false)
            // Today's timings, the FAQ and its structured data.
            ->assertSee('Today (')
            ->assertSee('What are the darshan timings of Sri Rama Temple?')
            ->assertSee('"@type":"FAQPage"', false)
            ->assertSee('Is there a dress code at Sri Rama Temple?')
            // A rich preview when shared.
            ->assertSee('<meta property="og:type" content="place">', false)
            ->assertSee('<meta name="twitter:title"', false);
    }

    public function test_the_directory_cards_show_what_a_temple_offers(): void
    {
        $this->temple();

        $this->get('https://darshansaathi.com/temples')->assertOk()
            ->assertSee('"@type":"ItemList"', false)
            ->assertSee('Book sevas online')
            ->assertSee('1 seva')
            ->assertSee('The temple of Sita Ramachandra on the Godavari.');
    }

    public function test_phones_learn_to_open_temple_links_in_the_app(): void
    {
        $this->getJson('https://darshansaathi.com/.well-known/assetlinks.json')->assertOk()->assertExactJson([]);

        $fp = implode(':', array_fill(0, 32, 'AB'));
        Setting::set('app_android_sha256', strtolower($fp));
        Setting::set('app_ios_app_id', 'TEAM123.com.darshansaathi.templevisit');

        $this->getJson('https://darshansaathi.com/.well-known/assetlinks.json')->assertOk()
            ->assertJsonPath('0.target.package_name', 'com.darshansaathi.templevisit')
            ->assertJsonPath('0.target.sha256_cert_fingerprints.0', $fp);
        $this->getJson('https://darshansaathi.com/.well-known/apple-app-site-association')->assertOk()
            ->assertJsonPath('applinks.details.0.appIDs.0', 'TEAM123.com.darshansaathi.templevisit')
            ->assertJsonPath('applinks.details.0.paths.0', '/temples/*');
    }

    public function test_new_and_changed_temples_are_sent_to_indexnow(): void
    {
        Http::fake(['api.indexnow.org/*' => Http::response('', 202)]);
        $this->temple();

        $key = IndexNow::key();
        $this->get('https://darshansaathi.com/'.$key.'.txt')->assertOk()->assertSee($key);
        $this->get('https://darshansaathi.com/'.str_repeat('0', 32).'.txt')->assertNotFound();

        $this->artisan('seo:indexnow')->assertSuccessful();

        Http::assertSent(fn ($request) => $request['host'] === 'darshansaathi.com'
            && $request['key'] === $key
            && in_array('https://darshansaathi.com/temples/sri-rama-bhadrachalam', $request['urlList'], true));

        // Nothing changed since: nothing sent.
        Http::fake(['api.indexnow.org/*' => Http::response('', 202)]);
        $this->artisan('seo:indexnow')->expectsOutputToContain('No temple pages changed')->assertSuccessful();
    }
}
