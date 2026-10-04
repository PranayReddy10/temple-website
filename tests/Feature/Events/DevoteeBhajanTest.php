<?php

namespace Tests\Feature\Events;

use App\Enums\EventStatus;
use App\Enums\TempleStatus;
use App\Enums\UserRole;
use App\Models\Devotee;
use App\Models\Temple;
use App\Models\TempleEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A devotee raises a bhajan gathering at a temple from the app, the way a
 * seva drive is raised: it waits for the editors, and it is always free.
 */
class DevoteeBhajanTest extends TestCase
{
    use RefreshDatabase;

    protected Temple $temple;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temple = Temple::create(['name' => 'Sri Rama Temple', 'slug' => 'sri-rama', 'city' => 'Bhadrachalam', 'status' => TempleStatus::Published, 'published_at' => now()]);
    }

    protected function devotee(): Devotee
    {
        $devotee = Devotee::factory()->create(['name' => 'Anu']);
        Sanctum::actingAs($devotee, guard: 'devotee');

        return $devotee;
    }

    public function test_a_devotee_raises_a_free_weekly_bhajan_that_waits_for_review(): void
    {
        $this->devotee();
        $day = now()->addDays(3)->toDateString();

        $this->postJson('/api/v1/temples/sri-rama/bhajans', [
            'title' => 'Friday Rama Bhajan',
            'group_name' => 'Sri Rama Bhajan Mandali',
            'description' => 'Bring your own cymbals.',
            'starts_on' => $day,
            'starts_at' => '18:30',
            'ends_at' => '20:00',
            'recurrence' => 'weekly',
            'songs' => "Raghupati Raghava\nSri Rama Jaya Rama",
            // Ignored: a devotee's bhajan cannot charge.
            'ticket_price' => 100,
            'registration_enabled' => false,
        ])->assertCreated()
            ->assertJsonPath('data.type', 'bhajan')
            ->assertJsonPath('data.status.value', 'pending_review')
            ->assertJsonPath('data.raised_by_devotee', true)
            ->assertJsonPath('data.raised_by', 'Anu')
            ->assertJsonPath('data.registration.is_paid', false)
            ->assertJsonPath('data.registration.price_paise', 0)
            ->assertJsonPath('data.recurrence', 'weekly')
            ->assertJsonPath('data.songs.1', 'Sri Rama Jaya Rama')
            ->assertJsonPath('data.temple.slug', 'sri-rama');

        $event = TempleEvent::query()->sole();
        $this->assertSame(EventStatus::PendingReview, $event->status);
        $this->assertTrue($event->registration_enabled);
        $this->assertSame(0, $event->ticket_price_paise);
        $this->assertNotNull($event->devotee_id);
        $this->assertNull($event->created_by);

        // Not on the temple's page until the editors publish it.
        $this->getJson('/api/v1/events?temple=sri-rama')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/me/bhajans')->assertOk()->assertJsonPath('data.0.title', 'Friday Rama Bhajan');

        // The editors see who raised it, and publishing opens it, free.
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        Sanctum::actingAs($admin, guard: 'trust');
        $this->getJson('/api/v1/trust/admin/events')->assertOk()
            ->assertJsonPath('data.0.raised_by_devotee', true)
            ->assertJsonPath('data.0.raised_by', 'Anu');
        $this->postJson('/api/v1/trust/admin/events/'.$event->id.'/approve')->assertOk();

        $this->getJson('/api/v1/events?temple=sri-rama&type=bhajan')->assertOk()
            ->assertJsonPath('data.0.registration.enabled', true)
            ->assertJsonPath('data.0.registration.is_paid', false);
    }

    public function test_a_bhajan_needs_a_signed_in_devotee_a_published_temple_and_a_day_ahead(): void
    {
        $day = now()->addDays(3)->toDateString();
        $this->postJson('/api/v1/temples/sri-rama/bhajans', ['title' => 'Bhajan', 'starts_on' => $day])->assertUnauthorized();

        $this->devotee();
        $this->postJson('/api/v1/temples/sri-rama/bhajans', ['title' => 'Bhajan', 'starts_on' => now()->subDay()->toDateString()])
            ->assertUnprocessable()->assertJsonValidationErrors(['starts_on']);
        $this->postJson('/api/v1/temples/sri-rama/bhajans', ['title' => 'Bhajan', 'starts_on' => $day, 'starts_at' => '19:00', 'ends_at' => '18:00'])
            ->assertUnprocessable()->assertJsonValidationErrors(['ends_at']);

        Temple::create(['name' => 'Draft Temple', 'slug' => 'draft-temple', 'status' => TempleStatus::Draft]);
        $this->postJson('/api/v1/temples/draft-temple/bhajans', ['title' => 'Bhajan', 'starts_on' => $day])->assertNotFound();
        $this->assertSame(0, TempleEvent::count());
    }
}
