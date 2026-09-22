<?php

namespace Tests\Feature\Filament;

use App\Enums\EventStatus;
use App\Enums\UserRole;
use App\Filament\Resources\TempleEvents\Pages\EditTempleEvent;
use App\Filament\Resources\TempleEvents\Pages\ListTempleEvents;
use App\Filament\Resources\TempleEvents\TempleEventResource;
use App\Models\Temple;
use App\Models\TempleEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Reviewing what temples submit, without knowing which temple sent it.
 *
 * Events are created inside a temple, which is where you want them when you
 * are thinking about that temple. Review is the opposite problem: a
 * submission arrives from a temple you were not thinking about, so a queue
 * that can only be reached through the right temple record is not a queue.
 */
class TempleEventQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function staff(UserRole $role = UserRole::SuperAdmin): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    protected function event(string $temple, string $title, EventStatus $status): TempleEvent
    {
        return TempleEvent::create([
            'temple_id' => Temple::create(['name' => $temple])->id,
            'title' => $title,
            'starts_on' => now()->toDateString(),
            'status' => $status,
        ]);
    }

    public function test_the_queue_lists_events_from_every_temple(): void
    {
        $this->actingAs($this->staff());

        $first = $this->event('Kashi Vishwanath', 'Maha Shivaratri', EventStatus::PendingReview);
        $second = $this->event('Rameswaram', 'Brahmotsavam', EventStatus::Published);

        Livewire::test(ListTempleEvents::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$first, $second])
            ->assertSee('Kashi Vishwanath')
            ->assertSee('Rameswaram');
    }

    public function test_an_editor_can_reach_the_queue(): void
    {
        $this->actingAs($this->staff(UserRole::Editor))
            ->get(TempleEventResource::getUrl('index'))
            ->assertOk();
    }

    /** The page you land on should be the one you clicked in the side menu. */
    public function test_the_headings_match_their_side_menu_labels(): void
    {
        $this->actingAs($this->staff());

        $this->assertSame(
            'Events & Programs',
            Livewire::test(ListTempleEvents::class)->instance()->getHeading(),
        );

        $this->assertSame(
            'Temple Trust Access',
            Livewire::test(\App\Filament\Resources\TempleAccess\Pages\ListTempleAccess::class)
                ->instance()->getHeading(),
        );
    }

    public function test_approving_a_submission_publishes_it_and_records_the_reviewer(): void
    {
        $admin = $this->staff();
        $this->actingAs($admin);

        $event = $this->event('Tirumala', 'Garuda Seva', EventStatus::PendingReview);

        Livewire::test(ListTempleEvents::class)
            ->callTableAction('approve', $event);

        $event->refresh();

        $this->assertSame(EventStatus::Published, $event->status);
        $this->assertSame($admin->id, $event->reviewed_by);
    }

    public function test_sending_a_submission_back_records_the_reason(): void
    {
        $admin = $this->staff();
        $this->actingAs($admin);

        $event = $this->event('Tirumala', 'Garuda Seva', EventStatus::PendingReview);

        Livewire::test(ListTempleEvents::class)
            ->callTableAction('reject', $event, data: ['review_note' => 'Dates are missing.']);

        $event->refresh();

        $this->assertSame(EventStatus::Rejected, $event->status);
        $this->assertSame('Dates are missing.', $event->review_note);
    }

    /** Review actions belong to a submission, not to anything already live. */
    public function test_a_published_event_offers_no_review_actions(): void
    {
        $this->actingAs($this->staff());

        $published = $this->event('Tirumala', 'Garuda Seva', EventStatus::Published);

        Livewire::test(ListTempleEvents::class)
            ->assertTableActionHidden('approve', $published)
            ->assertTableActionHidden('reject', $published);
    }

    public function test_the_navigation_badge_counts_only_submissions(): void
    {
        $this->actingAs($this->staff());

        $this->assertNull(TempleEventResource::getNavigationBadge());

        $this->event('Tirumala', 'Waiting', EventStatus::PendingReview);
        $this->event('Tirumala', 'Live already', EventStatus::Published);

        $this->assertSame('1', TempleEventResource::getNavigationBadge());
    }

    public function test_an_event_can_be_corrected_from_the_queue(): void
    {
        $this->actingAs($this->staff());

        $event = $this->event('Tirumala', 'Garuda Seva', EventStatus::PendingReview);

        Livewire::test(EditTempleEvent::class, ['record' => $event->getKey()])
            ->assertOk()
            ->fillForm(['title' => 'Garuda Vahana Seva'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Garuda Vahana Seva', $event->fresh()->title);
    }
}
