<?php

namespace Tests\Feature\Seo;

use App\Enums\TempleStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Temples\Pages\EditTemple;
use App\Filament\Resources\Temples\Pages\ListTemples;
use App\Filament\Resources\Temples\TempleResource;
use App\Models\District;
use App\Models\State;
use App\Models\Temple;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Nobody types a temple's full name. "swarnagiri temple bhuvanagiri" has to
 * find "Swarnagiri Sri Venkateswara Swamy Temple" in Bhuvanagiri, in the
 * admin, on the website and in the app, and the page has to read the way
 * people search it.
 */
class PartialSearchTest extends TestCase
{
    use RefreshDatabase;

    protected Temple $swarnagiri;

    protected Temple $other;

    protected function setUp(): void
    {
        parent::setUp();
        config(['brand.website' => 'https://darshansaathi.com']);

        $state = State::create(['name' => 'Telangana', 'slug' => 'telangana', 'code' => 'TG', 'type' => 'state']);
        $district = District::create(['state_id' => $state->id, 'name' => 'Yadadri Bhuvanagiri', 'slug' => 'yadadri-bhuvanagiri']);

        $this->swarnagiri = Temple::create([
            'name' => 'Swarnagiri Sri Venkateswara Swamy Temple', 'slug' => 'swarnagiri', 'city' => 'Bhuvanagiri',
            'state_id' => $state->id, 'district_id' => $district->id, 'pincode' => '508116',
            'status' => TempleStatus::Published, 'published_at' => now(),
        ]);
        $this->swarnagiri->aliases()->create(['name' => 'Swarnagiri Temple', 'locale' => 'en']);
        $this->swarnagiri->aliases()->create(['name' => 'స్వర్ణగిరి', 'locale' => 'te']);

        $this->other = Temple::create(['name' => 'Sri Venkateswara Swamy Temple', 'slug' => 'other-venkateswara', 'city' => 'Hyderabad', 'state_id' => $state->id, 'status' => TempleStatus::Published, 'published_at' => now()]);
    }

    public function test_search_matches_words_in_any_order_across_name_town_district_and_other_names(): void
    {
        foreach ([
            'swarnagiri temple bhuvanagiri',
            'Swarnagiri Bhuvanagiri',
            'bhuvanagiri venkateswara',
            'swarna giri',
            'venkateswara yadadri',
            'swarnagiri 508116',
            'స్వర్ణగిరి',
        ] as $search) {
            $this->assertSame(['swarnagiri'], Temple::query()->search($search)->pluck('slug')->all(), $search);
        }

        // Only the filler words: still a search, by those words.
        $this->assertCount(2, Temple::query()->search('sri temple')->get());
        $this->assertCount(2, Temple::query()->search('venkateswara temple')->get());
        $this->assertSame([], Temple::query()->search('swarnagiri hyderabad')->pluck('slug')->all());
    }

    public function test_the_website_and_app_find_it_the_same_way(): void
    {
        $this->get('https://darshansaathi.com/temples?q=swarnagiri+temple+bhuvanagiri')->assertOk()
            ->assertSee('Swarnagiri Sri Venkateswara Swamy Temple')
            ->assertDontSee('other-venkateswara');

        $this->getJson('/api/v1/temples?q=swarnagiri temple bhuvanagiri')->assertOk()
            ->assertJsonPath('data.0.slug', 'swarnagiri')
            ->assertJsonCount(1, 'data');
    }

    public function test_the_admin_list_and_global_search_find_it(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin]));

        Livewire::test(ListTemples::class)
            ->searchTable('swarnagiri temple bhuvanagiri')
            ->assertCanSeeTableRecords([$this->swarnagiri])
            ->assertCanNotSeeTableRecords([$this->other]);

        $this->assertSame(
            ['Swarnagiri Sri Venkateswara Swamy Temple'],
            TempleResource::getGlobalSearchResults('swarnagiri bhuvanagiri')->pluck('title')->map(fn ($t) => (string) $t)->all(),
        );
    }

    public function test_the_page_title_uses_the_short_name_people_search(): void
    {
        $this->get('https://darshansaathi.com/temples/swarnagiri')->assertOk()
            ->assertSee('<title>Swarnagiri Temple, Bhuvanagiri: Timings, Photos &amp; How to Reach', false)
            ->assertSee('<h1>Swarnagiri Sri Venkateswara Swamy Temple</h1>', false)
            ->assertSeeText('Swarnagiri Sri Venkateswara Swamy Temple (also called Swarnagiri Temple) is a Hindu temple in Bhuvanagiri, Yadadri Bhuvanagiri district, Telangana');
    }

    public function test_the_temple_admin_page_shows_the_score_and_opens_the_page(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin]));

        Livewire::test(EditTemple::class, ['record' => $this->swarnagiri->getKey()])
            ->assertSee('Page score 8%') // only the district of 12 checks
            ->assertSee('Add: Cover photo')
            ->assertActionHasUrl('viewPage', 'https://darshansaathi.com/temples/swarnagiri')
            ->assertActionHasLabel('viewPage', 'View page');

        // A draft opens a private preview instead.
        $draft = Temple::create(['name' => 'Draft Temple', 'slug' => 'draft-temple', 'status' => TempleStatus::Draft]);
        $url = Livewire::test(EditTemple::class, ['record' => $draft->getKey()])
            ->assertActionHasLabel('viewPage', 'Preview page')
            ->instance()->getAction('viewPage')->getUrl();

        $this->get($url)->assertOk()->assertSee('<h1>Draft Temple</h1>', false)->assertSee('noindex', false);
        $this->get(route('site.temple.preview', ['temple' => $draft->getKey()]))->assertForbidden();
        $this->get('https://darshansaathi.com/temples/draft-temple')->assertNotFound();
        $this->assertStringContainsString('signature=', $url);
        $this->assertTrue(URL::hasValidSignature(request()->create($url)));
    }
}
