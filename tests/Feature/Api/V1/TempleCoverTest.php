<?php

namespace Tests\Feature\Api\V1;

use App\Enums\TempleStatus;
use App\Models\Temple;
use App\Models\TemplePhoto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Which photo leads a temple on the home screen and at the top of its page. */
class TempleCoverTest extends TestCase
{
    use RefreshDatabase;

    protected function photo(Temple $t, string $path, array $attrs = []): TemplePhoto
    {
        $p = $t->photos()->create(['disk' => 'public', 'path' => $path, 'is_published' => true, ...$attrs]);

        return $p->fresh();
    }

    public function test_the_lead_photo_is_the_cover_in_the_list_and_on_the_page(): void
    {
        $t = Temple::create(['name' => 'Cover Temple', 'slug' => 'cover-temple', 'status' => TempleStatus::Published, 'published_at' => now()]);
        $this->photo($t, 'temples/1/a.jpg');
        $lead = $this->photo($t, 'temples/1/b.jpg', ['is_primary' => true]);

        $this->getJson('/api/v1/temples')->assertOk()->assertJsonPath('data.0.primary_photo.id', $lead->id);
        $this->getJson('/api/v1/temples/cover-temple')->assertOk()->assertJsonPath('data.primary_photo.id', $lead->id);
    }

    public function test_without_a_published_lead_the_first_published_photo_covers(): void
    {
        $t = Temple::create(['name' => 'Cover Temple', 'slug' => 'cover-temple', 'status' => TempleStatus::Published, 'published_at' => now()]);
        $first = $this->photo($t, 'temples/1/a.jpg');
        // The observer made the first photo the lead; hide it, as a
        // moderator might, and nothing is marked lead any more.
        $first->update(['is_published' => false]);
        $second = $this->photo($t, 'temples/1/b.jpg');
        TemplePhoto::query()->update(['is_primary' => false]);

        $this->getJson('/api/v1/temples')->assertOk()->assertJsonPath('data.0.primary_photo.id', $second->id);
        $this->getJson('/api/v1/temples/cover-temple')->assertOk()->assertJsonPath('data.primary_photo.id', $second->id);
    }

    /** Every screen that names a temple can show it: visits, yatra stops, reviews. */
    public function test_nested_temples_carry_their_cover(): void
    {
        $t = Temple::create(['name' => 'Cover Temple', 'slug' => 'cover-temple', 'status' => TempleStatus::Published, 'published_at' => now()]);
        $lead = $this->photo($t, 'temples/1/b.jpg', ['is_primary' => true, 'thumbnail_path' => 'temples/1/b-thumb.webp']);
        $devotee = \App\Models\Devotee::factory()->create();
        \Laravel\Sanctum\Sanctum::actingAs($devotee, guard: 'devotee');

        \App\Models\DevoteeVisit::create(['devotee_id' => $devotee->id, 'temple_id' => $t->id, 'method' => 'manual', 'visited_on' => '2026-09-01']);
        $this->getJson('/api/v1/me/visits')->assertOk()
            ->assertJsonPath('data.0.temple.cover.thumbnail', $lead->thumbnailUrl());

        $yatra = \App\Models\Yatra::create(['devotee_id' => $devotee->id, 'title' => 'Trip']);
        $yatra->stops()->create(['temple_id' => $t->id, 'day_number' => 1, 'sort_order' => 1]);
        $this->getJson("/api/v1/me/yatras/{$yatra->id}")->assertOk()
            ->assertJsonPath('data.stops.0.temple.cover.medium', $lead->mediumUrl());

        $this->postJson('/api/v1/temples/cover-temple/reviews', ['queue_rating' => 4])->assertCreated()
            ->assertJsonPath('data.temple.cover.original', $lead->url());
    }
}
