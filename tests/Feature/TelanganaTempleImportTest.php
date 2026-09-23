<?php

namespace Tests\Feature;

use App\Enums\TempleStatus;
use App\Enums\VerificationStatus;
use App\Models\Temple;
use Database\Seeders\DeitySeeder;
use Database\Seeders\StateSeeder;
use Database\Seeders\TelanganaTempleSeeder;
use Database\Seeders\TempleCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelanganaTempleImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([StateSeeder::class, DeitySeeder::class, TempleCategorySeeder::class]);
    }

    protected function yadadri(): ?Temple
    {
        return Temple::withTrashed()->where('slug', 'yadadri-lakshmi-narasimha-temple')->first();
    }

    public function test_it_imports_telangana_temples_as_published_community_records(): void
    {
        $this->artisan('temples:import-telangana')->assertSuccessful();

        $temples = Temple::whereHas('state', fn ($q) => $q->where('code', 'TG'))->get();

        $this->assertGreaterThanOrEqual(40, $temples->count());
        $this->assertTrue($temples->every(fn (Temple $t) => $t->status === TempleStatus::Published));
        // Nothing here was checked against a temple. It must not claim to be.
        $this->assertTrue($temples->every(fn (Temple $t) => $t->verification_status === VerificationStatus::Community));
        $this->assertTrue($temples->every(fn (Temple $t) => $t->last_verified_at === null));
    }

    public function test_famous_temples_are_featured_and_the_rest_are_not(): void
    {
        $this->seed(TelanganaTempleSeeder::class);

        $this->assertTrue($this->yadadri()->is_featured);
        $this->assertTrue(Temple::where('slug', 'ramappa-temple-kakatiya-rudreshwara-temple')->value('is_featured'));
        $this->assertFalse(Temple::where('slug', 'sanghi-temple')->value('is_featured'));
    }

    public function test_every_imported_puja_has_no_published_price(): void
    {
        $this->seed(TelanganaTempleSeeder::class);

        $pujas = $this->yadadri()->pujas;

        $this->assertNotEmpty($pujas);
        // Unknown price is "No published price", never an invented figure.
        $this->assertTrue($pujas->every(fn ($p) => $p->fee_amount === null && ! $p->booking_is_official));
        $this->assertSame('No published price', $pujas->first()->feeLabel());
    }

    public function test_imported_general_hours_say_they_are_unconfirmed(): void
    {
        $this->seed(TelanganaTempleSeeder::class);

        $general = $this->yadadri()->timings->firstWhere('kind.value', 'general');

        $this->assertStringContainsString('not confirmed', $general->notes);
    }

    public function test_running_it_twice_duplicates_nothing(): void
    {
        $this->seed(TelanganaTempleSeeder::class);
        $counts = [Temple::count(), $this->yadadri()->pujas()->count(), $this->yadadri()->timings()->count(), $this->yadadri()->aliases()->count()];

        $this->artisan('temples:import-telangana')
            ->expectsOutputToContain('0 created')
            ->assertSuccessful();

        $this->assertSame($counts, [Temple::count(), $this->yadadri()->pujas()->count(), $this->yadadri()->timings()->count(), $this->yadadri()->aliases()->count()]);
    }

    public function test_it_never_overwrites_what_an_editor_wrote(): void
    {
        $this->seed(TelanganaTempleSeeder::class);
        $this->yadadri()->update([
            'short_description' => 'Editor wording.',
            'status' => TempleStatus::InReview,
        ]);

        $this->seed(TelanganaTempleSeeder::class);

        $temple = $this->yadadri();
        $this->assertSame('Editor wording.', $temple->short_description);
        $this->assertSame(TempleStatus::InReview, $temple->status);
    }

    public function test_it_never_touches_a_verified_temple(): void
    {
        $this->seed(TelanganaTempleSeeder::class);
        $this->yadadri()->update([
            'verification_status' => VerificationStatus::Official,
            'source_name' => 'Devasthanam',
            'source_url' => 'https://example.org',
            'last_verified_at' => now()->toDateString(),
            'official_website' => null,
            'is_featured' => false,
        ]);

        $this->artisan('temples:import-telangana')
            ->expectsOutputToContain('1 left alone')
            ->assertSuccessful();

        $temple = $this->yadadri();
        $this->assertNull($temple->official_website);
        $this->assertFalse($temple->is_featured);
    }

    public function test_it_does_not_resurrect_a_deleted_temple(): void
    {
        $this->seed(TelanganaTempleSeeder::class);
        $this->yadadri()->delete();

        $this->seed(TelanganaTempleSeeder::class);

        $this->assertTrue($this->yadadri()->trashed());
    }

    public function test_the_api_filters_and_sorts_by_featured(): void
    {
        $this->seed(TelanganaTempleSeeder::class);

        $featured = $this->getJson('/api/v1/temples?featured=1&per_page=50')->assertOk();

        $this->assertNotEmpty($featured->json('data'));
        $this->assertTrue(collect($featured->json('data'))->every(fn ($t) => $t['is_featured'] === true));

        $sorted = $this->getJson('/api/v1/temples?sort=featured&per_page=50')->assertOk();
        $flags = array_column($sorted->json('data'), 'is_featured');
        $this->assertSame($flags, collect($flags)->sortDesc()->values()->all());

        $this->getJson('/api/v1/temples/chilkur-balaji-temple')
            ->assertOk()
            ->assertJsonPath('data.is_featured', true)
            ->assertJsonPath('data.trust.level', 'community');
    }

    public function test_it_fails_clearly_without_reference_data(): void
    {
        \App\Models\State::query()->delete();

        $this->artisan('temples:import-telangana')
            ->expectsOutputToContain('app:deploy')
            ->assertFailed();
    }
}
