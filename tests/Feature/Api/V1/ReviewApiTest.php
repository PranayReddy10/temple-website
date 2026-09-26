<?php

namespace Tests\Feature\Api\V1;

use App\Enums\ReviewStatus;
use App\Enums\TempleStatus;
use App\Enums\UserRole;
use App\Models\Devotee;
use App\Models\Temple;
use App\Models\TempleReview;
use App\Models\TempleUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Accounts of visits: rated per dimension of the visit, never the temple;
 * read by a moderator before anyone else; answerable by the temple.
 */
class ReviewApiTest extends TestCase
{
    use RefreshDatabase;

    protected Temple $temple;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temple = Temple::create(['name' => 'Review Temple', 'slug' => 'review-temple', 'status' => TempleStatus::Published, 'published_at' => now()]);
    }

    protected function signIn(string $name = 'Anuradha Rao'): Devotee
    {
        $devotee = Devotee::factory()->create(['name' => $name]);
        Sanctum::actingAs($devotee, guard: 'devotee');

        return $devotee;
    }

    protected function approve(TempleReview $review): TempleReview
    {
        $review->update(['status' => ReviewStatus::Approved, 'moderated_at' => now()]);

        return $review;
    }

    public function test_a_review_waits_for_a_moderator_and_its_author_sees_where_it_stands(): void
    {
        $this->signIn();

        $response = $this->postJson('/api/v1/temples/review-temple/reviews', [
            'visited_on' => '2026-09-20', 'queue_rating' => 2, 'accuracy_rating' => 5, 'wait_minutes' => 90, 'body' => 'Long queue on a Monday, but the timings here were exactly right.',
        ])->assertCreated()
            ->assertJsonPath('data.status.value', 'pending')
            ->assertJsonPath('data.is_mine', true)
            ->assertJsonPath('data.devotee.name', 'Anuradha R.')
            ->assertJsonPath('data.wait_minutes', 90);

        $ratings = collect($response->json('data.ratings'))->keyBy('key');
        $this->assertSame(2, $ratings['queue_rating']['value']);
        $this->assertNull($ratings['cleanliness_rating']['value']);

        // Nobody else reads it yet; the summary has nothing in it.
        $this->getJson('/api/v1/temples/review-temple/reviews')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.summary.count', 0);
        $this->getJson('/api/v1/me/reviews')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_published_reviews_are_summarised_per_dimension_with_no_overall_score(): void
    {
        $a = Devotee::factory()->create(['name' => 'Ravi Kumar']);
        $b = Devotee::factory()->create(['name' => 'Sita']);
        $this->approve(TempleReview::create(['devotee_id' => $a->id, 'temple_id' => $this->temple->id, 'visited_on' => '2026-09-01', 'queue_rating' => 2, 'cleanliness_rating' => 5, 'wait_minutes' => 60, 'body' => 'Crowded.']));
        $this->approve(TempleReview::create(['devotee_id' => $b->id, 'temple_id' => $this->temple->id, 'visited_on' => '2026-09-02', 'queue_rating' => 4, 'wait_minutes' => 20]));
        // Pending: counted nowhere.
        TempleReview::create(['devotee_id' => $a->id, 'temple_id' => $this->temple->id, 'visited_on' => '2026-09-03', 'queue_rating' => 1]);

        $response = $this->getJson('/api/v1/temples/review-temple/reviews')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.summary.count', 2)
            ->assertJsonPath('meta.summary.dimensions.queue_rating.average', 3)
            ->assertJsonPath('meta.summary.dimensions.queue_rating.count', 2)
            ->assertJsonPath('meta.summary.dimensions.cleanliness_rating.average', 5)
            ->assertJsonPath('meta.summary.dimensions.cleanliness_rating.count', 1)
            ->assertJsonPath('meta.summary.dimensions.facilities_rating.average', null)
            ->assertJsonPath('meta.summary.average_wait_minutes', 40)
            ->assertJsonPath('data.0.devotee.name', 'Sita')
            ->assertJsonPath('data.0.is_mine', false);

        $this->assertArrayNotHasKey('overall', $response->json('meta.summary'));
        $this->assertArrayNotHasKey('status', $response->json('data.0'), 'moderation state is the author\'s business');

        $this->getJson('/api/v1/temples/review-temple')->assertOk()
            ->assertJsonPath('data.engagement.reviews.count', 2)
            ->assertJsonPath('data.engagement.reviews.dimensions.queue_rating.average', 3);
    }

    public function test_writing_again_about_the_same_day_edits_and_sends_it_back_for_review(): void
    {
        $this->signIn();
        $this->postJson('/api/v1/temples/review-temple/reviews', ['visited_on' => '2026-09-20', 'queue_rating' => 2])->assertCreated();
        $this->approve(TempleReview::query()->firstOrFail());

        $this->postJson('/api/v1/temples/review-temple/reviews', ['visited_on' => '2026-09-20', 'queue_rating' => 3, 'body' => 'On reflection, not so bad.'])
            ->assertOk()->assertJsonPath('data.status.value', 'pending');

        $this->assertSame(1, TempleReview::query()->count());
        $this->assertSame(3, TempleReview::query()->firstOrFail()->queue_rating);
    }

    public function test_an_empty_review_a_future_visit_and_a_guest_are_refused(): void
    {
        $this->postJson('/api/v1/temples/review-temple/reviews', ['queue_rating' => 3])->assertUnauthorized();

        $this->signIn();
        $this->postJson('/api/v1/temples/review-temple/reviews', ['visited_on' => '2026-09-20'])->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->postJson('/api/v1/temples/review-temple/reviews', ['visited_on' => now()->addDays(3)->toDateString(), 'queue_rating' => 3])->assertUnprocessable()->assertJsonValidationErrors('visited_on');
        $this->postJson('/api/v1/temples/review-temple/reviews', ['queue_rating' => 6])->assertUnprocessable()->assertJsonValidationErrors('queue_rating');
    }

    public function test_a_devotee_removes_only_their_own(): void
    {
        $this->signIn();
        $id = $this->postJson('/api/v1/temples/review-temple/reviews', ['queue_rating' => 3])->json('data.id');

        Sanctum::actingAs(Devotee::factory()->create(), guard: 'devotee');
        $this->deleteJson("/api/v1/me/reviews/{$id}")->assertNotFound();

        Sanctum::actingAs(TempleReview::findOrFail($id)->devotee, guard: 'devotee');
        $this->deleteJson("/api/v1/me/reviews/{$id}")->assertOk();
        $this->assertSame(0, TempleReview::query()->count());
    }

    public function test_the_temples_team_reads_published_accounts_and_replies_but_cannot_publish(): void
    {
        $author = $this->signIn();
        $this->postJson('/api/v1/temples/review-temple/reviews', ['visited_on' => '2026-09-20', 'queue_rating' => 2, 'body' => 'Long queue.'])->assertCreated();
        $review = TempleReview::query()->firstOrFail();

        $admin = User::factory()->create(['role' => UserRole::TempleAdmin, 'is_active' => true]);
        TempleUser::create(['temple_id' => $this->temple->id, 'user_id' => $admin->id, 'requested_at' => now(), 'approved_at' => now()]);
        $this->actingAs($admin, 'web');

        // Pending: the temple does not see it yet.
        Livewire::test(\App\Filament\Resources\Temples\RelationManagers\ReviewsRelationManager::class, ['ownerRecord' => $this->temple, 'pageClass' => \App\Filament\Temple\Resources\MyTemples\Pages\EditMyTemple::class])
            ->assertCanNotSeeTableRecords([$review]);

        $this->approve($review);
        Livewire::test(\App\Filament\Resources\Temples\RelationManagers\ReviewsRelationManager::class, ['ownerRecord' => $this->temple, 'pageClass' => \App\Filament\Temple\Resources\MyTemples\Pages\EditMyTemple::class])
            ->assertCanSeeTableRecords([$review])
            ->assertTableActionHidden('approve', $review)
            ->assertTableActionHidden('reject', $review)
            ->callTableAction('reply', $review, ['temple_reply' => 'Weekday mornings are quieter; do come early.']);

        // The devotee sees the reply.
        Sanctum::actingAs($author, guard: 'devotee');
        $this->getJson('/api/v1/temples/review-temple/reviews')->assertOk()
            ->assertJsonPath('data.0.temple_reply', 'Weekday mornings are quieter; do come early.');
    }

    public function test_staff_moderate_from_the_queue(): void
    {
        $this->signIn();
        $this->postJson('/api/v1/temples/review-temple/reviews', ['queue_rating' => 1, 'body' => 'Terrible people.'])->assertCreated();
        $review = TempleReview::query()->firstOrFail();

        $this->actingAs(User::factory()->create(['role' => UserRole::Editor, 'is_active' => true]), 'web');
        $this->get('/admin/visit-reviews')->assertOk();

        Livewire::test(\App\Filament\Resources\TempleReviews\Pages\ListTempleReviews::class)
            ->assertCanSeeTableRecords([$review])
            ->callTableAction('reject', $review, ['moderation_note' => 'Speaks about people, not the visit.']);

        $this->assertSame(ReviewStatus::Rejected, $review->fresh()->status);
        $this->getJson('/api/v1/me/reviews')->assertJsonPath('data.0.status.value', 'rejected')->assertJsonPath('data.0.moderation_note', 'Speaks about people, not the visit.');
    }
}
