<?php

namespace Database\Seeders;

use App\Enums\TicketCategory;
use App\Enums\TicketKind;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Devotee;
use App\Models\SupportTicket;
use App\Models\Temple;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * A support queue with something in it, so the screen can be looked at.
 *
 * Not reference data: `app:deploy` never runs it and it refuses to run in
 * production. An empty queue is honest; one full of invented complaints is a
 * queue somebody will eventually work through.
 */
class DemoSupportSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->error('DemoSupportSeeder refuses to run in production.');

            return;
        }

        $temples = Temple::query()->published()->limit(5)->get();
        $devotees = Devotee::query()->limit(5)->get();
        $staff = User::query()->whereIn('role', ['super_admin', 'editor'])->first();

        $tickets = [
            [
                'kind' => TicketKind::Report,
                'category' => TicketCategory::WrongInformation,
                'subject' => 'Darshan timings are out of date',
                'body' => 'The listing says it opens at 5am but the board at the gate says 5:30am since last month.',
                'status' => TicketStatus::New,
                'about' => 0,
            ],
            [
                'kind' => TicketKind::Report,
                'category' => TicketCategory::InappropriateContent,
                'subject' => 'Photo is of a different temple',
                'body' => 'The main photo is of a temple in another town entirely.',
                'status' => TicketStatus::New,
                'about' => 1,
            ],
            [
                'kind' => TicketKind::Support,
                'category' => TicketCategory::Account,
                'subject' => 'Cannot sign in after changing my phone',
                'body' => 'The OTP never arrives on the new number.',
                'status' => TicketStatus::Open,
                'assigned' => true,
            ],
            [
                'kind' => TicketKind::Support,
                'category' => TicketCategory::AppProblem,
                'subject' => 'Passport screen is blank',
                'body' => 'It shows a spinner and nothing else after I record a visit.',
                'status' => TicketStatus::WaitingOnReporter,
                'assigned' => true,
                'replied' => true,
            ],
            [
                'kind' => TicketKind::Report,
                'category' => TicketCategory::Duplicate,
                'subject' => 'This temple is listed twice',
                'body' => 'There is another entry with almost the same name.',
                'status' => TicketStatus::Resolved,
                'about' => 2,
                'assigned' => true,
                'resolved' => true,
            ],
            [
                'kind' => TicketKind::Support,
                'category' => TicketCategory::Suggestion,
                'subject' => 'Please add Kannada',
                'body' => 'My parents would use the app if it were in Kannada.',
                'status' => TicketStatus::New,
            ],
            [
                'kind' => TicketKind::Support,
                'category' => TicketCategory::Booking,
                'subject' => 'Seva booking link goes nowhere',
                'body' => 'Tapping the archana booking link opens a page that says not found.',
                'status' => TicketStatus::New,
                'priority' => TicketPriority::High,
            ],
        ];

        foreach ($tickets as $index => $definition) {
            $ticket = new SupportTicket([
                'kind' => $definition['kind'],
                'category' => $definition['category'],
                'subject' => $definition['subject'],
                'body' => $definition['body'],
                'status' => $definition['status'],
                'priority' => $definition['priority'] ?? TicketPriority::Normal,
                'source' => 'app',
                'platform' => $index % 2 === 0 ? 'android' : 'ios',
                'app_version' => '1.0.0',
            ]);

            // A spread of signed-in and not, because both routes exist and
            // both have to render.
            if ($devotees->count() > 0 && $index % 3 !== 2) {
                $ticket->devotee_id = $devotees[$index % $devotees->count()]->id;
            } else {
                $ticket->reporter_name = 'A visitor';
                $ticket->reporter_email = 'visitor'.$index.'@example.test';
            }

            if (isset($definition['about']) && $temples->count() > $definition['about']) {
                $ticket->about()->associate($temples[$definition['about']]);
            }

            if (($definition['assigned'] ?? false) && $staff !== null) {
                $ticket->assigned_to = $staff->id;
            }

            $ticket->created_at = now()->subDays(10 - $index)->subHours($index * 3);
            $ticket->save();

            if ($definition['replied'] ?? false) {
                $ticket->messages()->create([
                    'author_type' => User::class,
                    'author_id' => $staff?->id,
                    'body' => 'Thank you for reporting this. Could you tell us which phone you are using?',
                    'is_internal' => false,
                ]);
                $ticket->messages()->create([
                    'author_type' => User::class,
                    'author_id' => $staff?->id,
                    'body' => 'Looks like the same crash as the one reported last week.',
                    'is_internal' => true,
                ]);
            }

            if ($definition['resolved'] ?? false) {
                $ticket->update([
                    'resolved_at' => now()->subDays(2),
                    'resolved_by' => $staff?->id,
                    'resolution_note' => 'Merged the duplicate into the original listing.',
                ]);
            }
        }

        $this->command?->info(count($tickets).' demo tickets, a mix of reports and support requests.');
    }
}
