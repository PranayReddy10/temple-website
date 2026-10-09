<?php

namespace Tests\Feature\Seo;

use App\Enums\TempleStatus;
use App\Models\Deity;
use App\Models\District;
use App\Models\State;
use App\Models\Temple;
use App\Support\IndexNow;
use App\Support\TempleSeo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * What makes a temple's page the answer when someone searches its name:
 * a full page, the temples and district around it, and the page in the
 * searcher's own language.
 */
class TemplePagesSeoTest extends TestCase
{
    use RefreshDatabase;

    protected State $state;

    protected District $district;

    protected function setUp(): void
    {
        parent::setUp();
        config(['brand.website' => 'https://darshansaathi.com']);
        Storage::fake(config('filesystems.media'));

        $this->state = State::create(['name' => 'Telangana', 'slug' => 'telangana', 'code' => 'TG', 'type' => 'state']);
        $this->district = District::create(['state_id' => $this->state->id, 'name' => 'Bhadradri Kothagudem', 'slug' => 'bhadradri-kothagudem']);
    }

    protected function temple(array $attrs = []): Temple
    {
        return Temple::create([
            'name' => 'Sri Sita Ramachandra Swamy Temple', 'slug' => 'bhadrachalam', 'city' => 'Bhadrachalam',
            'state_id' => $this->state->id, 'district_id' => $this->district->id,
            'latitude' => 17.6688, 'longitude' => 80.8936, 'pincode' => '507111',
            'status' => TempleStatus::Published, 'published_at' => now(), ...$attrs,
        ]);
    }

    public function test_a_temple_page_has_a_written_introduction_nearby_temples_and_how_to_reach(): void
    {
        $deity = Deity::create(['name' => 'Lord Rama', 'slug' => 'rama']);
        $temple = $this->temple(['deity_id' => $deity->id]);
        $temple->timings()->create(['kind' => 'darshan', 'opens_at' => '04:30', 'closes_at' => '21:00']);
        $temple->pujas()->create(['name' => 'Kalyanam', 'is_published' => true, 'is_free' => true]);
        $this->temple(['name' => 'Parnasala Temple', 'slug' => 'parnasala', 'city' => 'Parnasala', 'latitude' => 17.75, 'longitude' => 80.82]);
        $this->temple(['name' => 'Far Away Temple', 'slug' => 'far-away', 'city' => 'Hyderabad', 'latitude' => 17.38, 'longitude' => 78.48]);

        $this->get('https://darshansaathi.com/temples/bhadrachalam')->assertOk()
            ->assertSee('<title>Sri Sita Ramachandra Swamy Temple, Bhadrachalam Timings', false)
            ->assertSee('Darshan timings: 4:30 AM – 9:00 PM.', false)
            ->assertSeeText('About Sri Sita Ramachandra Swamy Temple')
            ->assertSeeText('is a Hindu temple dedicated to Lord Rama in Bhadrachalam, Bhadradri Kothagudem district, Telangana (507111).')
            ->assertSeeText('It is usually open for darshan from 4:30 AM to 9:00 PM.')
            ->assertSeeText('1 puja or seva is listed here, such as Kalyanam.')
            ->assertSeeText('Other temples nearby include Parnasala Temple (')
            ->assertSeeText('Temples near Sri Sita Ramachandra Swamy Temple')
            ->assertSeeText('How to reach Sri Sita Ramachandra Swamy Temple')
            ->assertSeeText('Sri Sita Ramachandra Swamy Temple timings')
            ->assertSee('https://darshansaathi.com/temples/parnasala', false)
            ->assertSeeText('Which temples are near Sri Sita Ramachandra Swamy Temple?')
            ->assertSee('"@type":["HinduTemple","TouristAttraction"]', false)
            ->assertSee('"containedInPlace":{"@type":"AdministrativeArea","name":"Bhadradri Kothagudem, Telangana","url":"https://darshansaathi.com/states/telangana/bhadradri-kothagudem"}', false)
            ->assertSee('"hasMap":"https://www.google.com/maps/search/?api=1', false)
            // Breadcrumbs go through the district.
            ->assertSee('"name":"Bhadradri Kothagudem","item":"https://darshansaathi.com/states/telangana/bhadradri-kothagudem"', false);

        // 140 km away is not "nearby" (it is listed under the state instead).
        $this->assertSame(['parnasala'], TempleSeo::nearby($temple)->pluck('slug')->all());
    }

    public function test_photos_are_described_for_image_search_and_all_listed_in_the_sitemap(): void
    {
        $temple = $this->temple();
        foreach ([1, 2] as $i) {
            $temple->photos()->create(['disk' => config('filesystems.media'), 'path' => "temples/1/p{$i}.jpg", 'is_published' => true, 'is_primary' => $i === 1, 'sort_order' => $i, 'caption' => $i === 2 ? 'Gopuram' : null]);
        }

        $this->get('https://darshansaathi.com/temples/bhadrachalam')->assertOk()
            ->assertSee('alt="Gopuram – Sri Sita Ramachandra Swamy Temple, Bhadrachalam"', false)
            ->assertSee('Photos of Sri Sita Ramachandra Swamy Temple');

        $xml = $this->get('/sitemap-temples-1.xml')->assertOk()->getContent();
        $this->assertSame(2, substr_count($xml, '<image:image>'));
    }

    public function test_district_pages_list_their_temples_and_are_linked_and_in_the_sitemap(): void
    {
        $this->temple();
        $other = District::create(['state_id' => $this->state->id, 'name' => 'Warangal', 'slug' => 'warangal']);
        $this->temple(['name' => 'Thousand Pillar Temple', 'slug' => 'thousand-pillar', 'city' => 'Hanamkonda', 'district_id' => $other->id]);
        District::create(['state_id' => $this->state->id, 'name' => 'Empty', 'slug' => 'empty']);

        $this->get('https://darshansaathi.com/states/telangana/bhadradri-kothagudem')->assertOk()
            ->assertSee('<title>Temples in Bhadradri Kothagudem, Telangana: Timings &amp; How to Reach', false)
            ->assertSeeText('Temples in Bhadradri Kothagudem district, Telangana')
            ->assertSee('Sri Sita Ramachandra Swamy Temple')->assertDontSee('Thousand Pillar Temple')
            ->assertSeeText('Temples in Bhadrachalam:')
            ->assertSee('<link rel="canonical" href="https://darshansaathi.com/states/telangana/bhadradri-kothagudem">', false);

        $this->get('https://darshansaathi.com/states/telangana/empty')->assertNotFound();
        $this->get('https://darshansaathi.com/states/telangana/nowhere')->assertNotFound();

        $this->get('https://darshansaathi.com/states/telangana')->assertOk()
            ->assertSee('https://darshansaathi.com/states/telangana/warangal', false)
            ->assertDontSee('states/telangana/empty');

        $this->get('/sitemap-pages.xml')
            ->assertSee('<loc>https://darshansaathi.com/states/telangana/bhadradri-kothagudem</loc>', false)
            ->assertDontSee('states/telangana/empty');
    }

    public function test_a_temple_page_is_published_in_each_language_its_name_is_translated_into(): void
    {
        $temple = $this->temple(['dress_code' => 'Traditional dress.']);
        $temple->setTranslation('name', 'te', 'శ్రీ సీతా రామచంద్ర స్వామి ఆలయం', isReviewed: true);
        $temple->setTranslation('dress_code', 'te', 'సాంప్రదాయ దుస్తులు.', isReviewed: true);
        // An unreviewed draft makes no page.
        $temple->setTranslation('name', 'hi', 'श्री सीता रामचंद्र स्वामी मंदिर', isReviewed: false);

        $this->get('https://darshansaathi.com/te/temples/bhadrachalam')->assertOk()
            ->assertSee('<html lang="te">', false)
            ->assertSee('<h1>శ్రీ సీతా రామచంద్ర స్వామి ఆలయం</h1>', false)
            ->assertSee('<link rel="canonical" href="https://darshansaathi.com/te/temples/bhadrachalam">', false)
            ->assertSee('<link rel="alternate" hreflang="en" href="https://darshansaathi.com/temples/bhadrachalam">', false)
            ->assertSee('<link rel="alternate" hreflang="x-default" href="https://darshansaathi.com/temples/bhadrachalam">', false)
            ->assertSeeText('సాంప్రదాయ దుస్తులు.')
            ->assertSeeText('దుస్తుల నియమం')
            ->assertSeeText('Sri Sita Ramachandra Swamy Temple')
            ->assertDontSee('noindex');

        // The English page links to the Telugu one.
        $this->get('https://darshansaathi.com/temples/bhadrachalam')->assertOk()
            ->assertSee('<html lang="en">', false)
            ->assertSee('<link rel="alternate" hreflang="te" href="https://darshansaathi.com/te/temples/bhadrachalam">', false)
            ->assertSeeText('Dress code')
            ->assertDontSee('hreflang="hi"', false);

        $this->get('https://darshansaathi.com/hi/temples/bhadrachalam')->assertRedirect('https://darshansaathi.com/temples/bhadrachalam');
        $this->get('https://darshansaathi.com/ml/temples/bhadrachalam')->assertNotFound();

        $xml = $this->get('/sitemap-temples-1.xml')->assertOk()->getContent();
        $this->assertStringContainsString('<loc>https://darshansaathi.com/te/temples/bhadrachalam</loc>', $xml);
        $this->assertStringContainsString('<xhtml:link rel="alternate" hreflang="te" href="https://darshansaathi.com/te/temples/bhadrachalam"/>', $xml);
        $this->assertStringNotContainsString('/hi/temples', $xml);

        $this->assertContains('https://darshansaathi.com/te/temples/bhadrachalam', IndexNow::templeUrls());
        $this->assertContains('https://darshansaathi.com/states/telangana/bhadradri-kothagudem', IndexNow::templeUrls());
    }

    public function test_translating_a_temple_marks_its_page_changed(): void
    {
        $temple = $this->temple();
        Temple::query()->whereKey($temple->id)->toBase()->update(['updated_at' => now()->subWeek()]);

        $temple->setTranslation('name', 'te', 'శ్రీ రామ ఆలయం', isReviewed: true);

        $this->assertTrue($temple->fresh()->updated_at->isToday());
    }

    public function test_the_home_page_links_into_the_directory(): void
    {
        $this->temple(['is_featured' => true]);

        $this->get('https://darshansaathi.com/')->assertOk()
            ->assertSee('href="https://darshansaathi.com/temples/bhadrachalam"', false)
            ->assertSee('Sri Sita Ramachandra Swamy Temple')
            ->assertSee('href="https://darshansaathi.com/states/telangana"', false)
            ->assertSee('"@type":"SearchAction"', false);
    }

    public function test_the_page_checklist_says_what_a_temple_page_still_needs(): void
    {
        $temple = $this->temple(['address' => 'Temple Street', 'short_description' => 'On the Godavari.']);

        $check = $temple->fresh()->pageChecklist();
        $this->assertContains('Timings', $check['missing']);
        $this->assertContains('Cover photo', $check['missing']);
        $this->assertNotContains('Map location', $check['missing']);
        $this->assertNotContains('Address and PIN', $check['missing']);
        // Description, map, address and district: 4 of 12.
        $this->assertSame(33, $check['score']);
    }
}
