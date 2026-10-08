<?php

namespace Tests\Feature\Http;

use App\Enums\TempleStatus;
use App\Enums\UserRole;
use App\Filament\Pages\Settings\ManageAnalytics;
use App\Models\Setting;
use App\Models\State;
use App\Models\Temple;
use App\Models\TemplePuja;
use App\Models\TempleTiming;
use App\Models\User;
use App\Support\IndexNow;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
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
            // Book on the website itself; open this temple in the installed
            // app (Android), else the home page's Get the app section.
            ->assertSee('href="https://darshansaathi.com/temples/sri-rama-bhadrachalam/sevas"', false)
            ->assertSee('href="https://darshansaathi.com/#app"', false)
            ->assertSee('data-intent="intent://darshansaathi.com/temples/sri-rama-bhadrachalam#Intent;scheme=https;', false)
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
        $this->artisan('seo:indexnow')->expectsOutputToContain('No pages changed')->assertSuccessful();
    }

    /** A change to timings, sevas or photos is a change to the temple's page. */
    public function test_a_change_to_a_temples_timings_sends_its_page_again(): void
    {
        Http::fake(['api.indexnow.org/*' => Http::response('', 202)]);
        $temple = $this->temple();
        $this->artisan('seo:indexnow')->assertSuccessful();

        $this->travel(5)->minutes();
        $before = $temple->refresh()->updated_at;
        $this->travel(1)->minutes();
        TempleTiming::create(['temple_id' => $temple->id, 'kind' => 'aarti', 'label' => 'Sandhya Arati', 'opens_at' => '19:00', 'days' => [6, 0]]);
        $this->assertTrue($temple->refresh()->updated_at->gt($before), 'the temple is marked changed');

        Http::fake(['api.indexnow.org/*' => Http::response('', 202)]);
        $this->artisan('seo:indexnow')->assertSuccessful();
        Http::assertSent(fn ($request) => in_array('https://darshansaathi.com/temples/sri-rama-bhadrachalam', $request['urlList'], true)
            && in_array('https://darshansaathi.com/states/telangana', $request['urlList'], true));
        $this->assertSame(3, (int) Setting::get('indexnow_last_count'));

        // The sitemap's date for the page moves with it.
        $this->get('https://darshansaathi.com/sitemap-temples-1.xml')->assertOk()
            ->assertSee($temple->updated_at->toAtomString(), false);
    }

    public function test_staff_see_when_pages_were_sent_and_can_send_them_all(): void
    {
        Http::fake(['api.indexnow.org/*' => Http::response('', 202)]);
        $this->temple();
        $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin, 'is_active' => true]));

        Livewire::test(ManageAnalytics::class)
            ->assertSee('Nothing sent yet')
            ->callAction(TestAction::make('sendAllPages')->schemaComponent('indexnow_actions'))
            ->assertHasNoErrors();

        Http::assertSent(fn ($request) => in_array('https://darshansaathi.com/temples/sri-rama-bhadrachalam', $request['urlList'], true));
        $this->assertNotNull(Setting::get('indexnow_last_sent'));
    }
}
