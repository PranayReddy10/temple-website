<?php

namespace Tests\Feature\Api\V1;

use App\Enums\CheckInMethod;
use App\Enums\TempleStatus;
use App\Models\Devotee;
use App\Models\DevoteeVisit;
use App\Models\Temple;
use App\Models\TempleCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Passport: recording visits, and what counts as a stamp.
 *
 * The load-bearing rule is that a manual check-in never verifies itself. If
 * it did, the collection would be a list anyone could type in, and the
 * circuits — "6 of 12 Jyotirlingas" — would mean nothing.
 */
class PassportApiTest extends TestCase
{
    use RefreshDatabase;

    /** Tirumala, to three decimal places. */
    protected const TEMPLE_LAT = 13.6833;

    protected const TEMPLE_LNG = 79.3472;

    protected function temple(array $attributes = []): Temple
    {
        return Temple::create(array_merge([
            'name' => 'Sri Venkateswara Temple',
            'status' => TempleStatus::Published,
            'latitude' => self::TEMPLE_LAT,
            'longitude' => self::TEMPLE_LNG,
        ], $attributes));
    }

    protected function signIn(): Devotee
    {
        $devotee = Devotee::factory()->create();
        Sanctum::actingAs($devotee, guard: 'devotee');

        return $devotee;
    }

    // --- Recording a visit ---

    public function test_a_devotee_can_record_a_manual_visit(): void
    {
        $temple = $this->temple();
        $this->signIn();

        $response = $this->postJson("/api/v1/temples/{$temple->slug}/visits", [
            'method' => 'manual',
            'visited_on' => '2026-01-14',
            'note' => 'Went with my grandmother.',
        ])->assertCreated();

        $this->assertSame('2026-01-14', $response->json('data.visited_on'));
        $this->assertFalse($response->json('data.is_verified'));
        $this->assertDatabaseCount('devotee_visits', 1);
    }

    /** A typed-in visit is a claim, and a claim never verifies itself. */
    public function test_a_manual_visit_is_never_verified_even_from_the_temple_gate(): void
    {
        $temple = $this->temple();
        $this->signIn();

        $this->postJson("/api/v1/temples/{$temple->slug}/visits", [
            'method' => 'manual',
            'latitude' => self::TEMPLE_LAT,
            'longitude' => self::TEMPLE_LNG,
        ])->assertCreated();

        $this->assertFalse(DevoteeVisit::firstOrFail()->is_verified);
    }

    public function test_a_gps_check_in_at_the_temple_verifies_itself(): void
    {
        $temple = $this->temple();
        $this->signIn();

        $response = $this->postJson("/api/v1/temples/{$temple->slug}/visits", [
            'method' => 'gps',
            'latitude' => self::TEMPLE_LAT,
            'longitude' => self::TEMPLE_LNG,
        ])->assertCreated();

        $this->assertTrue($response->json('data.is_verified'));
        // Standing at the temple is zero metres away, not NaN: the haversine
        // must use the asin form, because acos returns NaN at zero distance.
        $this->assertSame(0, $response->json('data.distance_metres'));
    }

    public function test_a_gps_check_in_from_far_away_is_recorded_but_not_verified(): void
    {
        $temple = $this->temple();
        $this->signIn();

        // Chennai, roughly 100km away.
        $response = $this->postJson("/api/v1/temples/{$temple->slug}/visits", [
            'method' => 'gps',
            'latitude' => 13.0827,
            'longitude' => 80.2707,
        ])->assertCreated();

        $this->assertFalse($response->json('data.is_verified'));
        $this->assertGreaterThan(50_000, $response->json('data.distance_metres'));
    }

    public function test_a_gps_check_in_must_carry_coordinates(): void
    {
        $temple = $this->temple();
        $this->signIn();

        $this->postJson("/api/v1/temples/{$temple->slug}/visits", ['method' => 'gps'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('latitude');
    }

    public function test_half_a_coordinate_pair_is_rejected(): void
    {
        $temple = $this->temple();
        $this->signIn();

        $this->postJson("/api/v1/temples/{$temple->slug}/visits", [
            'method' => 'manual',
            'latitude' => self::TEMPLE_LAT,
        ])->assertStatus(422)->assertJsonValidationErrors('longitude');
    }

    /** A passport records where you have been, not where you intend to go. */
    public function test_a_visit_cannot_be_dated_in_the_future(): void
    {
        $temple = $this->temple();
        $this->signIn();

        $this->postJson("/api/v1/temples/{$temple->slug}/visits", [
            'visited_on' => now()->addDay()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors('visited_on');
    }

    public function test_a_draft_temple_cannot_be_checked_into(): void
    {
        $temple = $this->temple(['name' => 'Unpublished', 'status' => TempleStatus::Draft]);
        $this->signIn();

        $this->postJson("/api/v1/temples/{$temple->slug}/visits", [])->assertNotFound();
        $this->assertDatabaseCount('devotee_visits', 0);
    }

    public function test_a_guest_cannot_record_a_visit(): void
    {
        $temple = $this->temple();

        $this->postJson("/api/v1/temples/{$temple->slug}/visits", [])->assertUnauthorized();
    }

    // --- Reading the passport back ---

    public function test_the_passport_counts_stamps_separately_from_visits(): void
    {
        $temple = $this->temple();
        $other = $this->temple(['name' => 'Another Temple']);
        $devotee = $this->signIn();

        // Two visits to one temple, one verified: one stamp, two visits.
        DevoteeVisit::create([
            'devotee_id' => $devotee->id, 'temple_id' => $temple->id,
            'visited_on' => '2026-01-01', 'is_verified' => true,
        ]);
        DevoteeVisit::create([
            'devotee_id' => $devotee->id, 'temple_id' => $temple->id,
            'visited_on' => '2026-02-01', 'is_verified' => true,
        ]);
        DevoteeVisit::create([
            'devotee_id' => $devotee->id, 'temple_id' => $other->id,
            'visited_on' => '2026-03-01', 'is_verified' => false,
        ]);

        $response = $this->getJson('/api/v1/me/passport')->assertOk();

        $this->assertSame(1, $response->json('data.stamps'));
        $this->assertSame(2, $response->json('data.temples_visited'));
        $this->assertSame(3, $response->json('data.visits_recorded'));
    }

    public function test_the_passport_reports_circuit_progress(): void
    {
        $circuit = TempleCategory::create([
            'name' => 'Jyotirlinga', 'slug' => 'jyotirlinga', 'kind' => 'circuit',
            'is_active' => true, 'expected_count' => 12,
        ]);

        $collected = $this->temple(['name' => 'Somnath']);
        $missed = $this->temple(['name' => 'Mallikarjuna']);
        $collected->categories()->attach($circuit);
        $missed->categories()->attach($circuit);

        $devotee = $this->signIn();

        DevoteeVisit::create([
            'devotee_id' => $devotee->id, 'temple_id' => $collected->id,
            'visited_on' => '2026-01-01', 'is_verified' => true,
        ]);

        $progress = $this->getJson('/api/v1/me/passport')->assertOk()->json('data.circuits.0');

        $this->assertSame('jyotirlinga', $progress['slug']);
        $this->assertSame(1, $progress['collected']);
        // Both what the database holds and what the circuit really contains,
        // so the app never tells a devotee to find a temple it cannot show.
        $this->assertSame(2, $progress['recorded']);
        $this->assertSame(12, $progress['total']);
        $this->assertFalse($progress['is_complete']);
    }

    public function test_one_devotee_never_sees_another_s_visits(): void
    {
        $stranger = Devotee::factory()->create();
        $temple = $this->temple();

        $theirVisit = DevoteeVisit::create([
            'devotee_id' => $stranger->id, 'temple_id' => $temple->id,
            'visited_on' => '2026-01-01',
        ]);

        $this->signIn();

        $this->getJson('/api/v1/me/visits')->assertOk()->assertJsonCount(0, 'data');
        // 404 rather than 403: confirming the id exists says something about
        // a stranger's pilgrimage.
        $this->deleteJson("/api/v1/me/visits/{$theirVisit->id}")->assertNotFound();
        $this->assertDatabaseHas('devotee_visits', ['id' => $theirVisit->id]);
    }

    public function test_a_devotee_can_remove_their_own_visit(): void
    {
        $temple = $this->temple();
        $devotee = $this->signIn();

        $visit = DevoteeVisit::create([
            'devotee_id' => $devotee->id, 'temple_id' => $temple->id,
            'visited_on' => '2026-01-01',
        ]);

        $this->deleteJson("/api/v1/me/visits/{$visit->id}")->assertOk();
        $this->assertDatabaseCount('devotee_visits', 0);
    }

    /**
     * The bug this file found: the publish guard called canPublish() on
     * whoever Auth::user() returned, and during a signed-in API request that
     * is a Devotee — which has no such method, so any temple write turned
     * into a 500 rather than a refusal.
     */
    public function test_a_temple_cannot_be_published_while_a_devotee_is_signed_in(): void
    {
        $this->signIn();

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);

        Temple::create(['name' => 'Snuck In', 'status' => TempleStatus::Published]);
    }

    /** And a devotee's id must never be written into a users.id column. */
    public function test_a_devotee_is_never_recorded_as_a_temple_s_author(): void
    {
        $this->signIn();

        $temple = Temple::create(['name' => 'Drafted', 'status' => TempleStatus::Draft]);

        $this->assertNull($temple->created_by);
        $this->assertNull($temple->updated_by);
    }

    public function test_the_check_in_method_reaches_the_client_with_its_label(): void
    {
        $temple = $this->temple();
        $this->signIn();

        $response = $this->postJson("/api/v1/temples/{$temple->slug}/visits", ['method' => 'qr'])
            ->assertCreated();

        $this->assertSame(CheckInMethod::Qr->value, $response->json('data.method.value'));
        $this->assertSame(CheckInMethod::Qr->getLabel(), $response->json('data.method.label'));
    }
}
