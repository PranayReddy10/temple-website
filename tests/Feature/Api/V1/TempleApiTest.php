<?php

namespace Tests\Feature\Api\V1;

use App\Enums\TempleStatus;
use App\Enums\VerificationStatus;
use App\Models\Deity;
use App\Models\State;
use App\Models\Temple;
use App\Models\TempleAlias;
use App\Models\TemplePuja;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TempleApiTest extends TestCase
{
    use RefreshDatabase;

    protected function publishedTemple(array $attributes = []): Temple
    {
        return Temple::create(array_merge([
            'name' => 'Published Temple',
            'status' => TempleStatus::Published,
        ], $attributes));
    }

    // --- Visibility ---

    public function test_only_published_temples_are_listed(): void
    {
        $this->publishedTemple(['name' => 'Visible Temple']);
        Temple::create(['name' => 'Draft Temple', 'status' => TempleStatus::Draft]);
        Temple::create(['name' => 'Review Temple', 'status' => TempleStatus::InReview]);

        $response = $this->getJson('/api/v1/temples')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Visible Temple', $response->json('data.0.name'));
    }

    public function test_an_unpublished_temple_returns_404_rather_than_403(): void
    {
        $draft = Temple::create(['name' => 'Secret Draft', 'status' => TempleStatus::Draft]);

        // 403 would confirm the slug exists; 404 reveals nothing.
        $this->getJson("/api/v1/temples/{$draft->slug}")->assertNotFound();
    }

    public function test_a_soft_deleted_temple_is_not_reachable(): void
    {
        $temple = $this->publishedTemple(['name' => 'Removed Temple']);
        $temple->delete();

        $this->getJson('/api/v1/temples')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/temples/{$temple->slug}")->assertNotFound();
    }

    // --- Search and filters ---

    public function test_search_matches_alternate_names(): void
    {
        $temple = $this->publishedTemple(['name' => 'Sri Venkateswara Swamy Temple']);
        TempleAlias::create(['temple_id' => $temple->id, 'name' => 'Tirupati Balaji', 'locale' => 'en']);
        $this->publishedTemple(['name' => 'Kedarnath Temple']);

        $response = $this->getJson('/api/v1/temples?q=Tirupati')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Sri Venkateswara Swamy Temple', $response->json('data.0.name'));
    }

    public function test_temples_can_be_filtered_by_deity(): void
    {
        $shiva = Deity::create(['name' => 'Shiva', 'slug' => 'shiva']);
        $vishnu = Deity::create(['name' => 'Vishnu', 'slug' => 'vishnu']);

        $this->publishedTemple(['name' => 'Shiva Temple', 'deity_id' => $shiva->id]);
        $this->publishedTemple(['name' => 'Vishnu Temple', 'deity_id' => $vishnu->id]);

        $response = $this->getJson('/api/v1/temples?deity=shiva')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Shiva Temple', $response->json('data.0.name'));
    }

    public function test_temples_can_be_filtered_by_state(): void
    {
        $tg = State::create(['name' => 'Telangana', 'slug' => 'telangana', 'code' => 'TG']);
        $tn = State::create(['name' => 'Tamil Nadu', 'slug' => 'tamil-nadu', 'code' => 'TN']);

        $this->publishedTemple(['name' => 'TG Temple', 'state_id' => $tg->id]);
        $this->publishedTemple(['name' => 'TN Temple', 'state_id' => $tn->id]);

        $response = $this->getJson('/api/v1/temples?state=telangana')->assertOk();

        $this->assertCount(1, $response->json('data'));
    }

    public function test_the_verified_filter_excludes_community_records(): void
    {
        $this->publishedTemple([
            'name' => 'Official Temple',
            'verification_status' => VerificationStatus::Official,
        ]);
        $this->publishedTemple([
            'name' => 'Community Temple',
            'verification_status' => VerificationStatus::Community,
        ]);

        $response = $this->getJson('/api/v1/temples?verified=1')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Official Temple', $response->json('data.0.name'));
    }

    // --- Proximity ---

    public function test_nearby_search_orders_by_real_distance(): void
    {
        // Charminar, Hyderabad.
        $this->publishedTemple(['name' => 'Hyderabad Temple', 'latitude' => 17.3616, 'longitude' => 78.4747]);
        // Warangal, roughly 135km away.
        $this->publishedTemple(['name' => 'Warangal Temple', 'latitude' => 17.9689, 'longitude' => 79.5941]);
        // Chennai, roughly 500km away and outside the radius.
        $this->publishedTemple(['name' => 'Chennai Temple', 'latitude' => 13.0827, 'longitude' => 80.2707]);

        $response = $this->getJson('/api/v1/temples?lat=17.3850&lng=78.4867&radius=200')->assertOk();

        $names = array_column($response->json('data'), 'name');
        $this->assertSame(['Hyderabad Temple', 'Warangal Temple'], $names);

        $first = $response->json('data.0.distance_km');
        $second = $response->json('data.1.distance_km');
        $this->assertLessThan(5, $first);
        $this->assertGreaterThan($first, $second);
    }

    public function test_distance_is_zero_for_an_exact_coordinate_match(): void
    {
        $this->publishedTemple(['name' => 'Exact Temple', 'latitude' => 17.3850, 'longitude' => 78.4867]);

        // The acos form of haversine returns NaN here and would drop the row.
        $response = $this->getJson('/api/v1/temples?lat=17.3850&lng=78.4867&radius=10')->assertOk();

        $this->assertCount(1, $response->json('data'));
        // JSON encodes 0.0 as 0, so compare numerically rather than by type.
        $this->assertEqualsWithDelta(0.0, $response->json('data.0.distance_km'), 0.001);
    }

    public function test_temples_without_coordinates_are_excluded_from_nearby_search(): void
    {
        $this->publishedTemple(['name' => 'Mapped Temple', 'latitude' => 17.3850, 'longitude' => 78.4867]);
        $this->publishedTemple(['name' => 'Unmapped Temple']);

        $response = $this->getJson('/api/v1/temples?lat=17.3850&lng=78.4867&radius=50')->assertOk();

        $this->assertCount(1, $response->json('data'));
    }

    public function test_latitude_without_longitude_is_rejected(): void
    {
        $this->getJson('/api/v1/temples?lat=17.38')
            ->assertStatus(422)
            ->assertJsonValidationErrors('lng');
    }

    public function test_an_out_of_range_coordinate_is_rejected(): void
    {
        $this->getJson('/api/v1/temples?lat=200&lng=78')
            ->assertStatus(422)
            ->assertJsonValidationErrors('lat');
    }

    public function test_distance_is_absent_when_not_searching_nearby(): void
    {
        $this->publishedTemple(['name' => 'Plain Temple', 'latitude' => 17.3, 'longitude' => 78.4]);

        $response = $this->getJson('/api/v1/temples')->assertOk();

        $this->assertArrayNotHasKey('distance_km', $response->json('data.0'));
    }

    // --- Detail payload ---

    public function test_the_detail_endpoint_exposes_trust_and_rules(): void
    {
        $temple = $this->publishedTemple([
            'name' => 'Detailed Temple',
            'verification_status' => VerificationStatus::Official,
            'source_name' => 'Temple trust website',
            'source_url' => 'https://temple.example',
            'last_verified_at' => now()->subMonth(),
            'dress_code' => 'Traditional dress required.',
        ]);

        $this->getJson("/api/v1/temples/{$temple->slug}")
            ->assertOk()
            ->assertJsonPath('data.trust.level', 'official')
            ->assertJsonPath('data.trust.source_name', 'Temple trust website')
            ->assertJsonPath('data.trust.is_stale', false)
            ->assertJsonPath('data.visitor_rules.dress_code', 'Traditional dress required.');
    }

    public function test_an_unpublished_puja_is_hidden_from_the_api(): void
    {
        $temple = $this->publishedTemple(['name' => 'Puja Temple']);
        TemplePuja::create(['temple_id' => $temple->id, 'name' => 'Visible Seva', 'is_published' => true]);
        TemplePuja::create(['temple_id' => $temple->id, 'name' => 'Hidden Seva', 'is_published' => false]);

        $response = $this->getJson("/api/v1/temples/{$temple->slug}")->assertOk();

        $this->assertCount(1, $response->json('data.pujas'));
        $this->assertSame('Visible Seva', $response->json('data.pujas.0.name'));
    }

    public function test_an_unpriced_puja_is_not_reported_as_free(): void
    {
        $temple = $this->publishedTemple(['name' => 'Fee Temple']);
        TemplePuja::create(['temple_id' => $temple->id, 'name' => 'Archana', 'fee_amount' => null]);

        $this->getJson("/api/v1/temples/{$temple->slug}")
            ->assertOk()
            ->assertJsonPath('data.pujas.0.fee.is_free', false)
            ->assertJsonPath('data.pujas.0.fee.amount', null)
            ->assertJsonPath('data.pujas.0.fee.label', 'No published price');
    }

    public function test_a_third_party_booking_link_is_not_marked_official(): void
    {
        $temple = $this->publishedTemple(['name' => 'Booking Temple']);
        TemplePuja::create([
            'temple_id' => $temple->id,
            'name' => 'Seva',
            'booking_url' => 'https://reseller.example',
            'booking_is_official' => false,
        ]);

        $this->getJson("/api/v1/temples/{$temple->slug}")
            ->assertOk()
            ->assertJsonPath('data.pujas.0.booking.is_official', false);
    }

    // --- Pagination ---

    public function test_results_are_paginated_with_a_capped_page_size(): void
    {
        foreach (range(1, 25) as $i) {
            $this->publishedTemple(['name' => "Temple {$i}"]);
        }

        $this->getJson('/api/v1/temples?per_page=5')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.total', 25);

        // An unbounded page size would let one request pull the whole database.
        $this->getJson('/api/v1/temples?per_page=500')->assertStatus(422);
    }

    // --- Supporting listings ---

    public function test_listing_endpoints_count_only_published_temples(): void
    {
        $deity = Deity::create(['name' => 'Shiva', 'slug' => 'shiva']);
        $this->publishedTemple(['name' => 'Published Shiva Temple', 'deity_id' => $deity->id]);
        Temple::create(['name' => 'Draft Shiva Temple', 'status' => TempleStatus::Draft, 'deity_id' => $deity->id]);

        $this->getJson('/api/v1/deities')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'shiva')
            // Tapping through must not show fewer temples than the badge promised.
            ->assertJsonPath('data.0.temple_count', 1);
    }

    public function test_the_supporting_listings_respond(): void
    {
        foreach (['/api/v1/deities', '/api/v1/categories', '/api/v1/states', '/api/v1/facilities'] as $url) {
            $this->getJson($url)->assertOk()->assertJsonStructure(['data']);
        }
    }
}
