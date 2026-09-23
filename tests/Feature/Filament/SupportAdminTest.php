<?php

namespace Tests\Feature\Filament;

use App\Enums\TicketKind;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Filament\Resources\SupportTickets\Pages\ListSupportTickets;
use App\Filament\Resources\SupportTickets\Pages\ViewSupportTicket;
use App\Filament\Resources\SupportTickets\RelationManagers\MessagesRelationManager;
use App\Filament\Resources\SupportTickets\SupportTicketResource;
use App\Models\Devotee;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SupportAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function staff(UserRole $role = UserRole::SuperAdmin): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    protected function ticket(array $attributes = []): SupportTicket
    {
        return SupportTicket::create(array_merge([
            'subject' => 'Timings are wrong',
            'body' => 'It closes at 1pm.',
            'reporter_name' => 'A Devotee',
            'reporter_email' => 'devotee@example.com',
        ], $attributes));
    }

    public function test_staff_can_reach_the_queue(): void
    {
        $this->actingAs($this->staff(UserRole::Editor))
            ->get(SupportTicketResource::getUrl('index'))
            ->assertOk();
    }

    public function test_a_temple_admin_cannot(): void
    {
        $this->actingAs($this->staff(UserRole::TempleAdmin))
            ->get(SupportTicketResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_the_queue_puts_the_worst_first_then_the_oldest(): void
    {
        $this->actingAs($this->staff());

        $oldNormal = $this->ticket(['subject' => 'Old normal']);
        $oldNormal->forceFill(['created_at' => now()->subWeek()])->save();

        $newUrgent = $this->ticket(['subject' => 'New urgent', 'priority' => TicketPriority::Urgent]);

        $newNormal = $this->ticket(['subject' => 'New normal']);

        Livewire::test(ListSupportTickets::class)
            ->set('activeTab', 'open')
            // Priority first, then age — neither alone gets this order.
            ->assertCanSeeTableRecords([$newUrgent, $oldNormal, $newNormal], inOrder: true);
    }

    /** The tab you land on is the one that would otherwise go unread. */
    public function test_it_opens_on_what_nobody_has_picked_up(): void
    {
        $this->actingAs($this->staff());
        $this->ticket();

        $this->assertSame('unassigned', Livewire::test(ListSupportTickets::class)->instance()->getDefaultActiveTab());
    }

    public function test_taking_a_ticket_assigns_it_and_stops_it_being_new(): void
    {
        $staff = $this->staff();
        $this->actingAs($staff);

        $ticket = $this->ticket();

        Livewire::test(ListSupportTickets::class)
            ->set('activeTab', 'open')
            ->callTableAction('assign_to_me', $ticket);

        $ticket->refresh();

        $this->assertSame($staff->id, $ticket->assigned_to);
        $this->assertSame(TicketStatus::Open, $ticket->status);
    }

    public function test_resolving_records_who_did_it_and_what_was_done(): void
    {
        $staff = $this->staff();
        $this->actingAs($staff);

        $ticket = $this->ticket();

        Livewire::test(ListSupportTickets::class)
            ->set('activeTab', 'open')
            ->callTableAction('resolve', $ticket, data: ['resolution_note' => 'Corrected the timings.']);

        $ticket->refresh();

        $this->assertSame(TicketStatus::Resolved, $ticket->status);
        $this->assertSame($staff->id, $ticket->resolved_by);
        $this->assertSame('Corrected the timings.', $ticket->resolution_note);
        $this->assertFalse($ticket->isOpen());
    }

    public function test_replying_puts_the_ticket_back_on_the_reporter(): void
    {
        $staff = $this->staff();
        $this->actingAs($staff);

        $ticket = $this->ticket();

        Livewire::test(ViewSupportTicket::class, ['record' => $ticket->getKey()])
            ->callAction('reply', data: ['body' => 'Thank you, we have corrected it.']);

        $ticket->refresh();

        // Otherwise an answered ticket looks identical to an untouched one.
        $this->assertSame(TicketStatus::WaitingOnReporter, $ticket->status);
        $this->assertSame($staff->id, $ticket->assigned_to);
        $this->assertFalse($ticket->messages()->first()->is_internal);
    }

    public function test_a_note_is_internal_and_does_not_change_the_status(): void
    {
        $this->actingAs($this->staff());

        $ticket = $this->ticket();

        Livewire::test(ViewSupportTicket::class, ['record' => $ticket->getKey()])
            ->callAction('note', data: ['body' => 'Checked with the temple office.']);

        $ticket->refresh();

        $this->assertTrue($ticket->messages()->first()->is_internal);
        $this->assertSame(TicketStatus::New, $ticket->status);
        // And it is not in what the reporter can see.
        $this->assertCount(0, $ticket->replies()->get());
    }

    public function test_the_conversation_shows_both_kinds_to_staff(): void
    {
        $this->actingAs($this->staff());

        $ticket = $this->ticket();
        $ticket->messages()->create(['body' => 'PUBLIC REPLY', 'is_internal' => false]);
        $ticket->messages()->create(['body' => 'INTERNAL NOTE', 'is_internal' => true]);

        Livewire::test(MessagesRelationManager::class, [
            'ownerRecord' => $ticket,
            'pageClass' => ViewSupportTicket::class,
        ])
            ->assertOk()
            ->assertSee('PUBLIC REPLY')
            ->assertSee('INTERNAL NOTE')
            // And can be narrowed to what the reporter actually got.
            ->filterTable('replies_only')
            ->assertSee('PUBLIC REPLY')
            ->assertDontSee('INTERNAL NOTE');
    }

    public function test_a_reply_cannot_be_deleted_but_a_note_can(): void
    {
        $this->actingAs($this->staff());

        $ticket = $this->ticket();
        $reply = $ticket->messages()->create(['body' => 'Sent', 'is_internal' => false]);
        $note = $ticket->messages()->create(['body' => 'Note', 'is_internal' => true]);

        $manager = fn () => Livewire::test(MessagesRelationManager::class, [
            'ownerRecord' => $ticket, 'pageClass' => ViewSupportTicket::class,
        ]);

        /*
         * Asserted by doing it rather than by inspecting the button.
         *
         * A "hidden" assertion passes just as happily when the action does
         * not exist at all, so on its own it would prove nothing about the
         * rule — only that nothing was rendered.
         */
        $manager()->callTableAction('delete_note', $note);
        $this->assertModelMissing($note);

        $manager()->assertTableActionHidden('delete_note', $reply);
        $this->assertModelExists($reply);
    }

    public function test_the_badge_counts_open_tickets_only(): void
    {
        $this->assertNull(SupportTicketResource::getNavigationBadge());

        $this->ticket();
        $this->ticket(['status' => TicketStatus::Resolved]);

        $this->assertSame('1', SupportTicketResource::getNavigationBadge());
    }

    public function test_the_badge_turns_red_when_something_is_urgent(): void
    {
        $this->ticket();
        $this->assertSame('warning', SupportTicketResource::getNavigationBadgeColor());

        $this->ticket(['priority' => TicketPriority::Urgent]);
        $this->assertSame('danger', SupportTicketResource::getNavigationBadgeColor());
    }

    public function test_a_report_links_through_to_what_it_is_about(): void
    {
        $this->actingAs($this->staff());

        $temple = \App\Models\Temple::create([
            'name' => 'Kashi Vishwanath',
            'status' => \App\Enums\TempleStatus::Draft,
        ]);

        $ticket = $this->ticket(['kind' => TicketKind::Report]);
        $ticket->about()->associate($temple);
        $ticket->save();

        $this->get(ViewSupportTicket::getUrl(['record' => $ticket]))
            ->assertOk()
            ->assertSee('Kashi Vishwanath')
            // A report you cannot act on from the ticket becomes a hunt.
            ->assertSee(\App\Filament\Resources\Temples\TempleResource::getUrl('edit', ['record' => $temple]));
    }

    /** A record whose subject was deleted must still open. */
    public function test_a_ticket_about_a_deleted_record_still_renders(): void
    {
        $this->actingAs($this->staff());

        $ticket = $this->ticket(['kind' => TicketKind::Report]);
        $ticket->forceFill(['about_type' => \App\Models\Temple::class, 'about_id' => 9999])->save();

        $this->get(ViewSupportTicket::getUrl(['record' => $ticket]))
            ->assertOk()
            ->assertSee('since deleted');
    }
}
