<?php

namespace Tests\Feature\Api\V1\Trust;

use App\Enums\TempleStatus;
use App\Enums\UserRole;
use App\Models\SupportTicket;
use App\Models\Temple;
use App\Models\TempleUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Temple teams write to support from the trust app; staff answer in the admin. */
class TrustSupportTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_temple_team_writes_in_and_reads_the_answer(): void
    {
        $temple = Temple::create(['name' => 'Sri Rama Temple', 'slug' => 'sri-rama', 'status' => TempleStatus::Published, 'published_at' => now()]);
        $other = Temple::create(['name' => 'Another', 'slug' => 'another', 'status' => TempleStatus::Published, 'published_at' => now()]);
        $team = User::factory()->create(['role' => UserRole::TempleAdmin, 'is_active' => true, 'name' => 'Trust Secretary']);
        TempleUser::create(['temple_id' => $temple->id, 'user_id' => $team->id, 'role' => 'owner', 'requested_at' => now(), 'approved_at' => now()]);
        $this->withToken($team->createToken('trust')->plainTextToken);

        $this->postJson('/api/v1/trust/support', ['subject' => 'Payout not received', 'body' => 'Settlement ST123 shows paid.', 'temple_id' => $other->id])->assertNotFound();

        $ref = $this->postJson('/api/v1/trust/support', ['subject' => 'Payout not received', 'body' => 'Settlement ST123 shows paid.', 'temple_id' => $temple->id])
            ->assertCreated()
            ->json('data.reference');

        $ticket = SupportTicket::query()->where('reference', $ref)->firstOrFail();
        $this->assertSame($team->id, $ticket->user_id);
        $this->assertSame('trust-app', $ticket->source);

        // Staff answer in the admin; the team's own reply is not "from staff".
        $staff = User::factory()->create(['role' => UserRole::SuperAdmin, 'is_active' => true]);
        $ticket->messages()->create(['author_type' => User::class, 'author_id' => $staff->id, 'body' => 'Checked: the UTR is on its way.', 'is_internal' => false]);
        $this->postJson("/api/v1/trust/support/{$ref}/replies", ['body' => 'Thank you.'])->assertOk();

        $this->getJson("/api/v1/trust/support/{$ref}")
            ->assertOk()
            ->assertJsonPath('data.messages.0.from_staff', true)
            ->assertJsonPath('data.messages.1.from_staff', false);

        $this->getJson('/api/v1/trust/support')->assertOk()->assertJsonCount(1, 'data');

        // Someone else's ticket reads as not found.
        $this->app['auth']->forgetGuards();
        $stranger = User::factory()->create(['role' => UserRole::TempleAdmin, 'is_active' => true]);
        $this->withToken($stranger->createToken('trust')->plainTextToken)->getJson("/api/v1/trust/support/{$ref}")->assertNotFound();
    }
}
