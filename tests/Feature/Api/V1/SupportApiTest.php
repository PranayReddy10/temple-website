<?php

namespace Tests\Feature\Api\V1;

use App\Enums\TempleStatus;
use App\Enums\TicketCategory;
use App\Enums\TicketKind;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Devotee;
use App\Models\SupportTicket;
use App\Models\Temple;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Telling us something is wrong.
 *
 * The rule that carries this: filing must not need an account. A report only
 * reaches us because somebody bothered, and a sign-in wall in front of it
 * means the listing with the wrong timings goes on sending devotees to a
 * closed gate.
 */
class SupportApiTest extends TestCase
{
    use RefreshDatabase;

    protected function temple(string $name = 'Kashi Vishwanath'): Temple
    {
        return Temple::create(['name' => $name, 'status' => TempleStatus::Published]);
    }

    protected function signIn(): Devotee
    {
        $devotee = Devotee::factory()->create();
        Sanctum::actingAs($devotee, guard: 'devotee');

        return $devotee;
    }

    // --- Filing ---

    public function test_anyone_can_file_a_support_request_without_an_account(): void
    {
        $response = $this->postJson('/api/v1/support', [
            'name' => 'A Devotee',
            'email' => 'devotee@example.com',
            'subject' => 'The app will not open',
            'body' => 'It closes as soon as I tap the icon.',
            'category' => TicketCategory::AppProblem->value,
        ])->assertCreated();

        // A reference they can quote back without an account or a link.
        $this->assertNotEmpty($response->json('data.reference'));
        $this->assertSame(TicketKind::Support->value, $response->json('data.kind'));
        $this->assertTrue($response->json('data.status.is_open'));

        $this->assertDatabaseHas('support_tickets', [
            'reporter_email' => 'devotee@example.com',
            'devotee_id' => null,
        ]);
    }

    public function test_a_name_is_required_when_nobody_is_signed_in(): void
    {
        $this->postJson('/api/v1/support', [
            'subject' => 'Anonymous',
            'body' => 'No way to write back.',
        ])->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_a_signed_in_devotee_does_not_have_to_repeat_their_details(): void
    {
        $devotee = $this->signIn();

        $this->postJson('/api/v1/support', [
            'subject' => 'A question',
            'body' => 'About my passport.',
        ])->assertCreated();

        $ticket = SupportTicket::firstOrFail();

        $this->assertSame($devotee->id, $ticket->devotee_id);
        $this->assertSame($devotee->name, $ticket->reporterName());
        $this->assertSame($devotee->email, $ticket->reporterEmail());
    }

    /** Pointing at a record makes it a report rather than a question. */
    public function test_a_ticket_about_a_temple_is_filed_as_a_report(): void
    {
        $temple = $this->temple();
        $this->signIn();

        $response = $this->postJson('/api/v1/support', [
            'subject' => 'Timings are wrong',
            'body' => 'It closes at 1pm, not 3pm.',
            'category' => TicketCategory::WrongInformation->value,
            'about_type' => 'temple',
            'about_id' => $temple->id,
        ])->assertCreated();

        $this->assertSame(TicketKind::Report->value, $response->json('data.kind'));
        $this->assertSame('Temple', $response->json('data.about.type'));

        $ticket = SupportTicket::firstOrFail();
        $this->assertTrue($ticket->about->is($temple));
    }

    /**
     * The allow-list. Without it a caller could point a ticket at any model
     * in the application — a User, say — and the admin would render whatever
     * came back.
     */
    public function test_a_report_cannot_be_pointed_at_an_arbitrary_model(): void
    {
        $staff = User::factory()->create();
        $this->signIn();

        $this->postJson('/api/v1/support', [
            'subject' => 'Nope',
            'body' => 'Trying to point at a staff account.',
            'about_type' => 'user',
            'about_id' => $staff->id,
        ])->assertStatus(422)->assertJsonValidationErrors('about_type');
    }

    public function test_a_report_against_something_that_does_not_exist_is_refused(): void
    {
        $this->signIn();

        $this->postJson('/api/v1/support', [
            'subject' => 'Ghost',
            'body' => 'Reporting a temple that is not there.',
            'about_type' => 'temple',
            'about_id' => 9999,
        ])->assertNotFound();

        $this->assertDatabaseCount('support_tickets', 0);
    }

    /** Content that should not be on a place of worship does not wait its turn. */
    public function test_inappropriate_content_is_filed_as_urgent(): void
    {
        $temple = $this->temple();
        $this->signIn();

        $this->postJson('/api/v1/support', [
            'subject' => 'Wrong photo',
            'body' => 'This is not a photo of this temple.',
            'category' => TicketCategory::InappropriateContent->value,
            'about_type' => 'temple',
            'about_id' => $temple->id,
        ])->assertCreated();

        $this->assertSame(TicketPriority::Urgent, SupportTicket::firstOrFail()->priority);
    }

    public function test_every_ticket_gets_a_unique_reference(): void
    {
        $this->signIn();

        $references = collect(range(1, 5))->map(fn (int $i): string => $this->postJson('/api/v1/support', [
            'subject' => "Ticket {$i}",
            'body' => 'Body.',
        ])->json('data.reference'));

        $this->assertCount(5, $references->unique());
    }

    // --- Reading back ---

    public function test_a_devotee_sees_their_own_tickets_and_not_anybody_else_s(): void
    {
        $stranger = Devotee::factory()->create();
        SupportTicket::create([
            'devotee_id' => $stranger->id,
            'subject' => 'Theirs',
            'body' => 'Private.',
        ]);

        $devotee = $this->signIn();
        SupportTicket::create([
            'devotee_id' => $devotee->id,
            'subject' => 'Mine',
            'body' => 'Mine.',
        ]);

        $response = $this->getJson('/api/v1/me/support')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Mine', $response->json('data.0.subject'));
    }

    /**
     * A reference is short enough to guess at, so the lookup is scoped rather
     * than trusting the reference to be secret.
     */
    public function test_a_reference_alone_does_not_open_somebody_else_s_ticket(): void
    {
        $stranger = Devotee::factory()->create();
        $theirs = SupportTicket::create([
            'devotee_id' => $stranger->id,
            'subject' => 'Theirs',
            'body' => 'Private.',
        ]);

        $this->signIn();

        $this->getJson("/api/v1/me/support/{$theirs->reference}")->assertNotFound();
    }

    /** The sharpest edge here: an internal note must never reach the reporter. */
    public function test_internal_notes_never_reach_the_reporter(): void
    {
        $devotee = $this->signIn();
        $staff = User::factory()->create(['name' => 'A Moderator']);

        $ticket = SupportTicket::create([
            'devotee_id' => $devotee->id,
            'subject' => 'A question',
            'body' => 'Body.',
        ]);

        $ticket->messages()->create([
            'author_type' => User::class,
            'author_id' => $staff->id,
            'body' => 'PUBLIC REPLY TO THE DEVOTEE',
            'is_internal' => false,
        ]);

        $ticket->messages()->create([
            'author_type' => User::class,
            'author_id' => $staff->id,
            'body' => 'INTERNAL NOTE ABOUT THIS PERSON',
            'is_internal' => true,
        ]);

        $response = $this->getJson("/api/v1/me/support/{$ticket->reference}")->assertOk();

        $response->assertDontSee('INTERNAL NOTE ABOUT THIS PERSON');
        $this->assertCount(1, $response->json('data.messages'));
        $this->assertSame('PUBLIC REPLY TO THE DEVOTEE', $response->json('data.messages.0.body'));
        $this->assertTrue($response->json('data.messages.0.from_staff'));
    }

    public function test_a_devotee_can_reply_and_that_reopens_the_ticket(): void
    {
        $devotee = $this->signIn();

        $ticket = SupportTicket::create([
            'devotee_id' => $devotee->id,
            'subject' => 'A question',
            'body' => 'Body.',
            'status' => TicketStatus::Resolved,
        ]);

        $this->postJson("/api/v1/me/support/{$ticket->reference}/replies", [
            'body' => 'That did not fix it.',
        ])->assertOk();

        // A resolution the reporter did not accept is not a resolution.
        $this->assertSame(TicketStatus::Open, $ticket->fresh()->status);
    }

    /** A devotee cannot write an internal note, whatever they send. */
    public function test_a_devotee_s_reply_is_never_internal(): void
    {
        $devotee = $this->signIn();

        $ticket = SupportTicket::create([
            'devotee_id' => $devotee->id,
            'subject' => 'A question',
            'body' => 'Body.',
        ]);

        $this->postJson("/api/v1/me/support/{$ticket->reference}/replies", [
            'body' => 'Trying to sneak a note in.',
            'is_internal' => true,
        ])->assertOk();

        $this->assertFalse($ticket->messages()->latest('id')->first()->is_internal);
    }

    public function test_the_categories_are_served_rather_than_compiled_into_the_app(): void
    {
        $response = $this->getJson('/api/v1/support/options')->assertOk();

        $categories = collect($response->json('data.categories'));

        $this->assertTrue($categories->pluck('value')->contains(TicketCategory::WrongInformation->value));
        // The description too, so the categories mean the same thing to the
        // person filing and the person reading.
        $this->assertNotEmpty($categories->firstWhere('value', 'wrong_information')['description']);
        $this->assertContains('temple', $response->json('data.reportable_types'));
    }
}
