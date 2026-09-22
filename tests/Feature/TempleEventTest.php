<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Enums\TempleStatus;
use App\Enums\UserRole;
use App\Enums\VerificationStatus;
use App\Models\Setting;
use App\Models\Temple;
use App\Models\TempleEvent;
use App\Models\TempleUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TempleEventTest extends TestCase
{
    use RefreshDatabase;

    protected function temple(VerificationStatus $level = VerificationStatus::Community): Temple
    {
        return Temple::create([
            'name' => 'Event Temple '.uniqid(),
            'status' => TempleStatus::Published,
            'verification_status' => $level,
        ]);
    }

    protected function templeAdminFor(Temple $temple): User
    {
        $user = User::factory()->create(['role' => UserRole::TempleAdmin, 'is_active' => true]);

        TempleUser::create([
            'temple_id' => $temple->id,
            'user_id' => $user->id,
            'requested_at' => now(),
            'approved_at' => now(),
        ]);

        return $user;
    }

    protected function event(Temple $temple, array $attributes = []): TempleEvent
    {
        return TempleEvent::create(array_merge([
            'temple_id' => $temple->id,
            'title' => 'Brahmotsavam',
            'starts_on' => now()->addWeek()->toDateString(),
        ], $attributes));
    }

    // --- Moderation ---

    public function test_a_verified_temple_may_publish_directly_when_enabled(): void
    {
        Setting::set('temple_self_publish_enabled', '1', 'boolean');
        $temple = $this->temple(VerificationStatus::Verified);
        $this->actingAs($this->templeAdminFor($temple));

        $event = $this->event($temple, ['status' => EventStatus::Published]);

        $this->assertSame(EventStatus::Published, $event->fresh()->status);
    }

    public function test_an_unverified_temple_is_queued_for_review(): void
    {
        Setting::set('temple_self_publish_enabled', '1', 'boolean');
        $temple = $this->temple(VerificationStatus::Community);
        $this->actingAs($this->templeAdminFor($temple));

        $event = $this->event($temple, ['status' => EventStatus::Published]);

        // Queued, not refused: the temple's work is kept and staff decide.
        $this->assertSame(EventStatus::PendingReview, $event->fresh()->status);
        $this->assertSame('Brahmotsavam', $event->fresh()->title);
    }

    public function test_the_setting_switches_self_publishing_off_entirely(): void
    {
        Setting::set('temple_self_publish_enabled', '0', 'boolean');
        $temple = $this->temple(VerificationStatus::Official);
        $this->actingAs($this->templeAdminFor($temple));

        $event = $this->event($temple, ['status' => EventStatus::Published]);

        // Even an official temple queues when the switch is off.
        $this->assertSame(EventStatus::PendingReview, $event->fresh()->status);
    }

    public function test_staff_publish_directly_whatever_the_temple(): void
    {
        Setting::set('temple_self_publish_enabled', '0', 'boolean');
        $temple = $this->temple(VerificationStatus::Unverified);
        $this->actingAs(User::factory()->create(['role' => UserRole::Editor, 'is_active' => true]));

        $event = $this->event($temple, ['status' => EventStatus::Published]);

        $this->assertSame(EventStatus::Published, $event->fresh()->status);
    }

    public function test_seeders_and_imports_publish_without_a_signed_in_user(): void
    {
        $event = $this->event($this->temple(), ['status' => EventStatus::Published]);

        $this->assertSame(EventStatus::Published, $event->fresh()->status);
    }

    public function test_published_at_is_stamped_once_and_not_reset(): void
    {
        $event = $this->event($this->temple(), ['status' => EventStatus::Published]);
        $first = $event->fresh()->published_at;

        $this->assertNotNull($first);

        $event->update(['description' => 'Edited later.']);

        $this->assertEquals($first, $event->fresh()->published_at);
    }

    // --- Dates ---

    public function test_a_single_day_event_needs_no_end_date(): void
    {
        $event = $this->event($this->temple(), ['starts_on' => now()->toDateString()]);

        $this->assertTrue($event->coversDate(now()));
        $this->assertFalse($event->coversDate(now()->addDay()));
        $this->assertSame(now()->format('d M Y'), $event->dateLabel());
    }

    public function test_a_range_covers_every_day_within_it(): void
    {
        $event = $this->event($this->temple(), [
            'starts_on' => now()->subDay()->toDateString(),
            'ends_on' => now()->addDays(2)->toDateString(),
        ]);

        $this->assertTrue($event->coversDate(now()));
        $this->assertTrue($event->coversDate(now()->addDays(2)));
        $this->assertFalse($event->coversDate(now()->addDays(3)));
    }

    public function test_upcoming_excludes_finished_events(): void
    {
        $temple = $this->temple();
        $this->event($temple, ['title' => 'Past', 'starts_on' => now()->subMonth()->toDateString()]);
        $this->event($temple, ['title' => 'Future', 'starts_on' => now()->addMonth()->toDateString()]);

        $titles = TempleEvent::upcoming()->pluck('title')->all();

        $this->assertSame(['Future'], $titles);
    }

    // --- API ---

    public function test_only_published_events_reach_the_api(): void
    {
        $temple = $this->temple();
        $this->event($temple, ['title' => 'Live', 'status' => EventStatus::Published]);
        $this->event($temple, ['title' => 'Draft', 'status' => EventStatus::Draft]);
        $this->event($temple, ['title' => 'Queued', 'status' => EventStatus::PendingReview]);

        $response = $this->getJson('/api/v1/events')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Live', $response->json('data.0.title'));
    }

    public function test_an_event_on_an_unpublished_temple_is_hidden(): void
    {
        $draftTemple = Temple::create(['name' => 'Draft Temple', 'status' => TempleStatus::Draft]);
        $this->event($draftTemple, ['title' => 'Hidden', 'status' => EventStatus::Published]);

        // Otherwise the event leaks the existence of an unready temple.
        $this->getJson('/api/v1/events')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_events_can_be_filtered_by_temple_and_type(): void
    {
        $a = $this->temple();
        $b = $this->temple();
        $this->event($a, ['title' => 'A Festival', 'type' => 'festival', 'status' => EventStatus::Published]);
        $this->event($b, ['title' => 'B Program', 'type' => 'program', 'status' => EventStatus::Published]);

        $this->getJson('/api/v1/events?temple='.$a->slug)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'A Festival');

        $this->getJson('/api/v1/events?type=program')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'B Program');
    }

    public function test_the_temple_detail_carries_its_upcoming_events(): void
    {
        $temple = $this->temple();
        $this->event($temple, ['title' => 'Upcoming Festival', 'status' => EventStatus::Published]);
        $this->event($temple, ['title' => 'Old Festival', 'status' => EventStatus::Published, 'starts_on' => now()->subYear()->toDateString()]);

        $response = $this->getJson("/api/v1/temples/{$temple->slug}")->assertOk();

        $this->assertCount(1, $response->json('data.events'));
        $this->assertSame('Upcoming Festival', $response->json('data.events.0.title'));
    }
}
