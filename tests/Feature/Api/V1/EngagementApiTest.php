<?php

namespace Tests\Feature\Api\V1;

use App\Enums\TempleStatus;
use App\Models\AppNotification;
use App\Models\Devotee;
use App\Models\Temple;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Likes and follows: the lightest things a devotee can do to a temple,
 * kept apart from saving, and from each other.
 */
class EngagementApiTest extends TestCase
{
    use RefreshDatabase;

    protected function temple(string $name = 'Kashi Vishwanath', TempleStatus $status = TempleStatus::Published): Temple
    {
        return Temple::create(['name' => $name, 'status' => $status, 'published_at' => now()]);
    }

    protected function signIn(): Devotee
    {
        $devotee = Devotee::factory()->create();
        Sanctum::actingAs($devotee, guard: 'devotee');

        return $devotee;
    }

    public function test_a_like_is_one_tap_counted_once(): void
    {
        $temple = $this->temple();
        $this->signIn();

        $this->putJson("/api/v1/me/likes/{$temple->slug}")->assertCreated()
            ->assertJsonPath('data.likes_count', 1)
            ->assertJsonPath('data.viewer.liked', true)
            ->assertJsonPath('data.viewer.following', false)
            ->assertJsonPath('data.viewer.saved', false);

        // Sent twice from a phone that was offline: still one.
        $this->putJson("/api/v1/me/likes/{$temple->slug}")->assertCreated()->assertJsonPath('data.likes_count', 1);
        $this->getJson('/api/v1/me/likes')->assertOk()->assertJsonCount(1, 'data');

        $this->deleteJson("/api/v1/me/likes/{$temple->slug}")->assertOk()->assertJsonPath('data.likes_count', 0)->assertJsonPath('data.viewer.liked', false);
    }

    public function test_following_asks_for_reminders_and_re_following_keeps_the_choices(): void
    {
        $temple = $this->temple();
        $this->signIn();

        $this->putJson("/api/v1/me/follows/{$temple->slug}")->assertCreated()
            ->assertJsonPath('data.follows_count', 1)
            ->assertJsonPath('data.viewer.following', true)
            ->assertJsonPath('data.viewer.notify_festivals', true)
            ->assertJsonPath('data.viewer.notify_events', true);

        $this->putJson("/api/v1/me/follows/{$temple->slug}", ['notify_events' => false])->assertOk()
            ->assertJsonPath('data.viewer.notify_festivals', true)
            ->assertJsonPath('data.viewer.notify_events', false);

        // A bare re-follow (the app syncing) does not switch reminders back on.
        $this->putJson("/api/v1/me/follows/{$temple->slug}")->assertOk()->assertJsonPath('data.viewer.notify_events', false);

        $this->getJson('/api/v1/me/follows')->assertOk()
            ->assertJsonPath('data.0.temple.slug', $temple->slug)
            ->assertJsonPath('data.0.notify_events', false);

        $this->deleteJson("/api/v1/me/follows/{$temple->slug}")->assertOk()->assertJsonPath('data.follows_count', 0);
    }

    public function test_the_temple_page_carries_counts_and_where_the_caller_stands(): void
    {
        $temple = $this->temple();
        $other = Devotee::factory()->create();
        $other->likes()->create(['temple_id' => $temple->id]);
        $other->follows()->create(['temple_id' => $temple->id]);

        // A guest sees the counts and no viewer.
        $this->getJson("/api/v1/temples/{$temple->slug}")->assertOk()
            ->assertJsonPath('data.engagement.likes_count', 1)
            ->assertJsonPath('data.engagement.follows_count', 1)
            ->assertJsonPath('data.engagement.viewer', null)
            ->assertJsonPath('data.engagement.reviews.count', 0);

        $this->signIn();
        $this->putJson("/api/v1/me/likes/{$temple->slug}");
        $this->getJson("/api/v1/temples/{$temple->slug}")->assertOk()
            ->assertJsonPath('data.engagement.likes_count', 2)
            ->assertJsonPath('data.engagement.viewer.liked', true)
            ->assertJsonPath('data.engagement.viewer.following', false);
    }

    public function test_a_draft_temple_cannot_be_liked_or_followed_and_a_guest_cannot_either(): void
    {
        $draft = $this->temple('Draft', TempleStatus::Draft);
        $this->putJson("/api/v1/me/likes/{$draft->slug}")->assertUnauthorized();

        $this->signIn();
        $this->putJson("/api/v1/me/likes/{$draft->slug}")->assertNotFound();
        $this->putJson("/api/v1/me/follows/{$draft->slug}")->assertNotFound();
    }

    public function test_a_temple_notification_reaches_followers_not_savers(): void
    {
        $temple = $this->temple();
        $follower = Devotee::factory()->create();
        $follower->follows()->create(['temple_id' => $temple->id]);
        $saver = Devotee::factory()->create();
        $saver->savedTemples()->attach($temple->id);

        AppNotification::create(['title' => 'Brahmotsavam', 'body' => 'Starts Friday.', 'audience' => 'temple', 'audience_id' => $temple->id])
            ->forceFill(['status' => 'sent', 'sent_at' => now()])->save();

        Sanctum::actingAs($follower, guard: 'devotee');
        $this->getJson('/api/v1/notifications?platform=android')->assertOk()->assertJsonCount(1, 'data');

        Sanctum::actingAs($saver, guard: 'devotee');
        $this->getJson('/api/v1/notifications?platform=android')->assertOk()->assertJsonCount(0, 'data');
    }
}
