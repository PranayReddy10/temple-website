<?php

namespace Tests\Feature\Seo;

use App\Enums\TempleStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Temples\Pages\EditTemple;
use App\Http\Controllers\PublicTempleController;
use App\Models\Temple;
use App\Models\TempleCategory;
use App\Models\User;
use App\Support\IndexNow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Search keywords and tags, set per temple in the admin. */
class KeywordsAndTagsTest extends TestCase
{
    use RefreshDatabase;

    protected Temple $temple;

    protected function setUp(): void
    {
        parent::setUp();
        config(['brand.website' => 'https://darshansaathi.com']);

        $this->temple = Temple::create([
            'name' => 'Swarnagiri Sri Venkateswara Swamy Temple', 'slug' => 'swarnagiri', 'city' => 'Bhuvanagiri',
            'search_keywords' => 'bhongir temple, manepally hills, స్వర్ణగిరి గుడి',
            'status' => TempleStatus::Published, 'published_at' => now(),
        ]);
        Temple::create(['name' => 'Other Temple', 'slug' => 'other', 'status' => TempleStatus::Published, 'published_at' => now()]);
    }

    public function test_search_keywords_find_the_temple_and_show_on_its_page(): void
    {
        foreach (['bhongir', 'manepally hills temple', 'స్వర్ణగిరి గుడి'] as $search) {
            $this->assertSame(['swarnagiri'], Temple::query()->search($search)->pluck('slug')->all(), $search);
        }

        $this->assertSame(['bhongir temple', 'manepally hills', 'స్వర్ణగిరి గుడి'], $this->temple->searchKeywords());

        $this->get('https://darshansaathi.com/temples/swarnagiri')->assertOk()
            ->assertSeeText('Also searched as bhongir temple, manepally hills, స్వర్ణగిరి గుడి');
    }

    public function test_tags_have_their_own_pages_linked_from_temples_and_in_the_sitemap(): void
    {
        $hill = TempleCategory::create(['name' => 'Hill temple', 'slug' => 'hill-temple', 'kind' => 'type', 'is_active' => true, 'description' => 'Temples on hills.']);
        $hidden = TempleCategory::create(['name' => 'Hidden', 'slug' => 'hidden', 'kind' => 'type', 'is_active' => false]);
        $empty = TempleCategory::create(['name' => 'Empty', 'slug' => 'empty', 'kind' => 'type', 'is_active' => true]);
        $this->temple->categories()->attach([$hill->id, $hidden->id]);

        $this->get('https://darshansaathi.com/tags/hill-temple')->assertOk()
            ->assertSee('<title>Hill temples: Timings, Photos &amp; How to Reach', false)
            ->assertSeeText('Hill temples')
            ->assertSeeText('Temples on hills.')
            ->assertSee('Swarnagiri Sri Venkateswara Swamy Temple')
            ->assertDontSee('Other Temple')
            ->assertSee('<link rel="canonical" href="https://darshansaathi.com/tags/hill-temple">', false);
        $this->get('https://darshansaathi.com/tags/hidden')->assertNotFound();
        $this->get('https://darshansaathi.com/tags/empty')->assertNotFound();

        $this->get('https://darshansaathi.com/temples/swarnagiri')->assertOk()
            ->assertSee('href="https://darshansaathi.com/tags/hill-temple"', false)
            ->assertDontSee('tags/hidden');
        $this->get('https://darshansaathi.com/temples')->assertSee('https://darshansaathi.com/tags/hill-temple', false);

        $this->get('/sitemap-pages.xml')->assertSee('<loc>https://darshansaathi.com/tags/hill-temple</loc>', false)
            ->assertDontSee('tags/empty');
        $this->assertContains('https://darshansaathi.com/tags/hill-temple', IndexNow::templeUrls());

        $this->assertSame('Jyotirlinga temples', PublicTempleController::tagHeading('Jyotirlinga'));
        $this->assertSame('Hill temples', PublicTempleController::tagHeading('Hill temples'));
    }

    public function test_the_admin_sets_keywords_and_creates_tags_on_the_temple(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin]));

        Livewire::test(EditTemple::class, ['record' => $this->temple->getKey()])
            ->assertFormFieldExists('search_keywords')
            ->fillForm(['search_keywords' => ['bhongir temple', 'yadadri route temple']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('bhongir temple,yadadri route temple', $this->temple->fresh()->search_keywords);

        Livewire::test(EditTemple::class, ['record' => $this->temple->getKey()])
            ->callFormComponentAction('categories', 'createOption', ['name' => 'Venkateswara hill shrines']);

        $this->assertDatabaseHas('temple_categories', ['slug' => 'venkateswara-hill-shrines', 'kind' => 'type', 'is_active' => true]);
    }
}
