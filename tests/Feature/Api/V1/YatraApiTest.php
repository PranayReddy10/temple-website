<?php

namespace Tests\Feature\Api\V1;

use App\Enums\TempleStatus;
use App\Enums\YatraStatus;
use App\Models\Devotee;
use App\Models\Temple;
use App\Models\Yatra;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class YatraApiTest extends TestCase
{
    use RefreshDatabase;

    protected function temple(string $name, array $attributes = []): Temple
    {
        return Temple::create(array_merge([
            'name' => $name,
            'status' => TempleStatus::Published,
        ], $attributes));
    }

    protected function signIn(): Devotee
    {
        $devotee = Devotee::factory()->create();
        Sanctum::actingAs($devotee, guard: 'devotee');

        return $devotee;
    }

    public function test_a_devotee_can_plan_a_trip(): void
    {
        $this->signIn();

        $response = $this->postJson('/api/v1/me/yatras', [
            'title' => 'Char Dham 2027',
            'starts_on' => '2027-05-01',
            'ends_on' => '2027-05-12',
            'party_size' => 4,
        ])->assertCreated();

        $this->assertSame('Char Dham 2027', $response->json('data.title'));
        $this->assertSame(YatraStatus::Planning->value, $response->json('data.status.value'));
        $this->assertTrue($response->json('data.status.is_upcoming'));
        // Inclusive of both ends: 1 May to 12 May is twelve days, not eleven.
        $this->assertSame(12, $response->json('data.day_count'));
    }

    public function test_a_trip_cannot_end_before_it_starts(): void
    {
        $this->signIn();

        $this->postJson('/api/v1/me/yatras', [
            'title' => 'Backwards',
            'starts_on' => '2027-05-12',
            'ends_on' => '2027-05-01',
        ])->assertStatus(422)->assertJsonValidationErrors('ends_on');
    }

    public function test_temples_are_added_in_order_within_a_day(): void
    {
        $first = $this->temple('Kedarnath');
        $second = $this->temple('Badrinath');
        $this->signIn();

        $yatra = $this->postJson('/api/v1/me/yatras', ['title' => 'Do Dham'])->json('data.id');

        $this->putJson("/api/v1/me/yatras/{$yatra}/temples/{$first->slug}", ['day_number' => 1])
            ->assertCreated();

        $response = $this->putJson("/api/v1/me/yatras/{$yatra}/temples/{$second->slug}", ['day_number' => 1])
            ->assertCreated();

        $stops = $response->json('data.stops');

        $this->assertCount(2, $stops);
        $this->assertSame('Kedarnath', $stops[0]['temple']['name']);
        $this->assertSame('Badrinath', $stops[1]['temple']['name']);
        // Appended rather than colliding at zero, so the order survives.
        $this->assertLessThan($stops[1]['sort_order'], $stops[0]['sort_order']);
    }

    /** A retried request on a flaky connection must not 500 on the unique index. */
    public function test_adding_the_same_temple_twice_is_idempotent(): void
    {
        $temple = $this->temple('Rameswaram');
        $this->signIn();

        $yatra = $this->postJson('/api/v1/me/yatras', ['title' => 'South'])->json('data.id');

        $this->putJson("/api/v1/me/yatras/{$yatra}/temples/{$temple->slug}")->assertCreated();
        $response = $this->putJson("/api/v1/me/yatras/{$yatra}/temples/{$temple->slug}")->assertCreated();

        $this->assertCount(1, $response->json('data.stops'));
    }

    public function test_a_draft_temple_cannot_be_added_to_a_trip(): void
    {
        $temple = $this->temple('Unpublished', ['status' => TempleStatus::Draft]);
        $this->signIn();

        $yatra = $this->postJson('/api/v1/me/yatras', ['title' => 'Nope'])->json('data.id');

        $this->putJson("/api/v1/me/yatras/{$yatra}/temples/{$temple->slug}")->assertNotFound();
    }

    /**
     * The loop between planning and the Passport: checking in closes the stop
     * that was planning for it.
     */
    public function test_recording_a_visit_marks_the_planned_stop_as_done(): void
    {
        $temple = $this->temple('Somnath', ['latitude' => 20.888, 'longitude' => 70.401]);
        $this->signIn();

        $yatra = $this->postJson('/api/v1/me/yatras', ['title' => 'Jyotirlinga run'])->json('data.id');
        $this->putJson("/api/v1/me/yatras/{$yatra}/temples/{$temple->slug}")->assertCreated();

        $this->assertFalse($this->getJson("/api/v1/me/yatras/{$yatra}")->json('data.stops.0.is_visited'));

        $this->postJson("/api/v1/temples/{$temple->slug}/visits", ['method' => 'manual'])->assertCreated();

        $stop = $this->getJson("/api/v1/me/yatras/{$yatra}")->json('data.stops.0');

        $this->assertTrue($stop['is_visited']);
        $this->assertNotNull($stop['visit_id']);
    }

    /** A completed trip is history; a later visit must not rewrite it. */
    public function test_a_visit_does_not_touch_a_completed_trip(): void
    {
        $temple = $this->temple('Somnath');
        $devotee = $this->signIn();

        $yatra = Yatra::create([
            'devotee_id' => $devotee->id,
            'title' => 'Last year',
            'status' => YatraStatus::Completed,
        ]);
        $yatra->stops()->create(['temple_id' => $temple->id]);

        $this->postJson("/api/v1/temples/{$temple->slug}/visits", ['method' => 'manual'])->assertCreated();

        $this->assertNull($yatra->stops()->first()->devotee_visit_id);
    }

    public function test_one_devotee_cannot_read_or_change_another_s_trip(): void
    {
        $stranger = Devotee::factory()->create();
        $theirs = Yatra::create(['devotee_id' => $stranger->id, 'title' => 'Private plans']);

        $this->signIn();

        $this->getJson("/api/v1/me/yatras/{$theirs->id}")->assertNotFound();
        $this->patchJson("/api/v1/me/yatras/{$theirs->id}", ['title' => 'Hijacked'])->assertNotFound();
        $this->deleteJson("/api/v1/me/yatras/{$theirs->id}")->assertNotFound();

        $this->assertSame('Private plans', $theirs->fresh()->title);
    }

    public function test_the_trip_list_shows_only_the_signed_in_devotee_s_trips(): void
    {
        $stranger = Devotee::factory()->create();
        Yatra::create(['devotee_id' => $stranger->id, 'title' => 'Theirs']);

        $devotee = $this->signIn();
        Yatra::create(['devotee_id' => $devotee->id, 'title' => 'Mine']);

        $response = $this->getJson('/api/v1/me/yatras')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Mine', $response->json('data.0.title'));
    }

    public function test_a_guest_cannot_reach_the_planner(): void
    {
        $this->getJson('/api/v1/me/yatras')->assertUnauthorized();
        $this->postJson('/api/v1/me/yatras', ['title' => 'x'])->assertUnauthorized();
    }

    /** "How many are planning a trip" has one definition, on the enum. */
    public function test_upcoming_covers_planning_confirmed_and_on_the_road(): void
    {
        $devotee = $this->signIn();

        foreach (YatraStatus::cases() as $status) {
            Yatra::create([
                'devotee_id' => $devotee->id,
                'title' => $status->value,
                'status' => $status,
            ]);
        }

        $this->assertSame(3, Yatra::query()->upcoming()->count());
        $this->assertEqualsCanonicalizing(
            ['planning', 'confirmed', 'in_progress'],
            YatraStatus::upcomingValues(),
        );
    }
}
