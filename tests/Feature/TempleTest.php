<?php

namespace Tests\Feature;

use App\Enums\TempleStatus;
use App\Enums\UserRole;
use App\Enums\VerificationStatus;
use App\Models\Temple;
use App\Models\TempleAlias;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TempleTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_generates_a_slug_from_the_name(): void
    {
        $temple = Temple::create(['name' => 'Kashi Vishwanath Temple']);

        $this->assertSame('kashi-vishwanath-temple', $temple->slug);
    }

    public function test_it_disambiguates_duplicate_temple_names(): void
    {
        // Temple names repeat constantly across India.
        $first = Temple::create(['name' => 'Shiva Temple']);
        $second = Temple::create(['name' => 'Shiva Temple']);
        $third = Temple::create(['name' => 'Shiva Temple']);

        $this->assertSame('shiva-temple', $first->slug);
        $this->assertSame('shiva-temple-2', $second->slug);
        $this->assertSame('shiva-temple-3', $third->slug);
    }

    public function test_it_keeps_the_slug_of_a_published_temple_stable(): void
    {
        $temple = Temple::create([
            'name' => 'Somnath Temple',
            'status' => TempleStatus::Published,
        ]);

        $temple->update(['name' => 'Somnath Jyotirlinga Temple']);

        // A published URL must not break because an editor corrected the name.
        $this->assertSame('somnath-temple', $temple->fresh()->slug);
    }

    public function test_it_stamps_published_at_once_and_does_not_reset_it(): void
    {
        $temple = Temple::create(['name' => 'Lingaraj Temple']);
        $this->assertNull($temple->published_at);

        $temple->update(['status' => TempleStatus::Published]);
        $firstPublish = $temple->fresh()->published_at;
        $this->assertNotNull($firstPublish);

        $temple->update(['short_description' => 'Edited later.']);

        $this->assertEquals($firstPublish, $temple->fresh()->published_at);
    }

    public function test_an_editor_cannot_publish_a_temple(): void
    {
        $editor = User::factory()->create(['role' => UserRole::Editor]);
        $this->actingAs($editor);

        $this->expectException(AuthorizationException::class);

        Temple::create([
            'name' => 'Unreviewed Temple',
            'status' => TempleStatus::Published,
        ]);
    }

    public function test_a_super_admin_can_publish_a_temple(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $this->actingAs($admin);

        $temple = Temple::create([
            'name' => 'Reviewed Temple',
            'status' => TempleStatus::Published,
        ]);

        $this->assertSame(TempleStatus::Published, $temple->status);
        $this->assertSame($admin->id, $temple->created_by);
    }

    public function test_seeders_and_imports_may_publish_without_a_signed_in_user(): void
    {
        $temple = Temple::create([
            'name' => 'Imported Temple',
            'status' => TempleStatus::Published,
        ]);

        $this->assertSame(TempleStatus::Published, $temple->fresh()->status);
    }

    public function test_published_scope_returns_only_published_temples(): void
    {
        Temple::create(['name' => 'Live Temple', 'status' => TempleStatus::Published]);
        Temple::create(['name' => 'Draft Temple', 'status' => TempleStatus::Draft]);
        Temple::create(['name' => 'Review Temple', 'status' => TempleStatus::InReview]);

        $this->assertSame(1, Temple::published()->count());
        $this->assertSame('Live Temple', Temple::published()->first()->name);
    }

    public function test_search_matches_alternate_and_local_names(): void
    {
        $temple = Temple::create(['name' => 'Sri Venkateswara Swamy Temple']);
        TempleAlias::create(['temple_id' => $temple->id, 'name' => 'Tirupati Balaji', 'locale' => 'en']);

        // The whole point of aliases: "Tirupati" must find this record.
        $this->assertSame(1, Temple::search('Tirupati')->count());
        $this->assertSame(1, Temple::search('Venkateswara')->count());
        $this->assertSame(0, Temple::search('Kedarnath')->count());
    }

    public function test_search_ignores_a_blank_term(): void
    {
        Temple::create(['name' => 'Temple A']);
        Temple::create(['name' => 'Temple B']);

        $this->assertSame(2, Temple::search('   ')->count());
        $this->assertSame(2, Temple::search(null)->count());
    }

    public function test_bounding_box_finds_nearby_temples_and_excludes_far_ones(): void
    {
        // Charminar area, Hyderabad.
        $near = Temple::create([
            'name' => 'Hyderabad Temple',
            'latitude' => 17.3850,
            'longitude' => 78.4867,
        ]);

        // Chennai, roughly 500km away.
        Temple::create([
            'name' => 'Chennai Temple',
            'latitude' => 13.0827,
            'longitude' => 80.2707,
        ]);

        $results = Temple::withinBoundingBox(17.3850, 78.4867, 50)->get();

        $this->assertCount(1, $results);
        $this->assertTrue($results->first()->is($near));
    }

    public function test_a_temple_is_stale_when_never_verified(): void
    {
        $temple = Temple::create(['name' => 'Unchecked Temple']);

        $this->assertTrue($temple->isStale());
    }

    public function test_a_recently_verified_temple_is_not_stale(): void
    {
        $temple = Temple::create([
            'name' => 'Checked Temple',
            'verification_status' => VerificationStatus::Official,
            'last_verified_at' => now()->subMonth(),
        ]);

        $this->assertFalse($temple->isStale());
        $this->assertTrue($temple->isStale(months: 0));
    }
}
