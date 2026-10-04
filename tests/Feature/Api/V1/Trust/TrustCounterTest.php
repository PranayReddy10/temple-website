<?php

namespace Tests\Feature\Api\V1\Trust;

use App\Enums\TempleStatus;
use App\Enums\UserRole;
use App\Models\Devotee;
use App\Models\Temple;
use App\Models\TemplePuja;
use App\Models\TempleUser;
use App\Models\User;
use App\Support\PassportQr;
use App\Support\TempleQr;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the trust app does at the gate and the counter, as the portal does:
 * the temple's check-in code, stamping a scanned passport, and editing a
 * seva's time slots.
 */
class TrustCounterTest extends TestCase
{
    use RefreshDatabase;

    protected Temple $temple;

    protected Temple $other;

    protected User $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temple = Temple::create(['name' => 'Sri Rama Temple', 'slug' => 'sri-rama', 'status' => TempleStatus::Published, 'published_at' => now()]);
        $this->other = Temple::create(['name' => 'Another Temple', 'slug' => 'another', 'status' => TempleStatus::Published, 'published_at' => now()]);
        $this->team = User::factory()->create(['role' => UserRole::TempleAdmin, 'is_active' => true]);
        TempleUser::create(['temple_id' => $this->temple->id, 'user_id' => $this->team->id, 'role' => 'manager', 'requested_at' => now(), 'approved_at' => now()]);
        $this->withToken($this->team->createToken('trust')->plainTextToken);
    }

    public function test_the_team_gets_its_temples_signed_check_in_code(): void
    {
        $this->getJson('/api/v1/trust/temples/'.$this->temple->id.'/qr')
            ->assertOk()
            ->assertJsonPath('data.url', TempleQr::url($this->temple))
            ->assertJsonPath('data.is_published', true)
            ->assertJsonPath('data.print_url', route('temples.qr.print', $this->temple));

        $this->getJson('/api/v1/trust/temples/'.$this->other->id.'/qr')->assertNotFound();
    }

    public function test_a_scanned_passport_is_stamped_once_a_day_at_the_teams_temple_only(): void
    {
        $devotee = Devotee::factory()->create();
        $code = PassportQr::url($devotee);

        $this->postJson('/api/v1/trust/passports/visit', ['code' => $code, 'temple_id' => $this->temple->id])
            ->assertOk()
            ->assertJsonPath('data.outcome', 'created');

        $this->assertSame(1, $devotee->visits()->where('temple_id', $this->temple->id)->where('is_verified', true)->count());

        $this->postJson('/api/v1/trust/passports/visit', ['code' => $code, 'temple_id' => $this->temple->id])
            ->assertOk()
            ->assertJsonPath('data.outcome', 'already');

        $this->postJson('/api/v1/trust/passports/visit', ['code' => $code, 'temple_id' => $this->other->id])->assertNotFound();
        $this->postJson('/api/v1/trust/passports/visit', ['code' => 'nonsense', 'temple_id' => $this->temple->id])->assertNotFound();
    }

    public function test_the_seva_editor_sees_and_replaces_every_slot(): void
    {
        $puja = TemplePuja::create(['temple_id' => $this->temple->id, 'name' => 'Archana', 'is_free' => true, 'app_booking_enabled' => true]);
        $url = '/api/v1/trust/temples/'.$this->temple->id.'/sevas/'.$puja->id;

        $this->post($url, [
            'name' => 'Archana', 'kind' => 'seva', 'is_free' => '1',
            'slots' => [
                ['starts_at' => '09:00', 'ends_at' => '10:00', 'capacity' => 15, 'days' => [0, 6], 'is_active' => '1'],
                ['starts_at' => '10:00', 'ends_at' => '11:00', 'is_active' => '0'],
            ],
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonCount(2, 'data.raw.slots')
            ->assertJsonPath('data.raw.slots.0.capacity', 15)
            ->assertJsonPath('data.raw.slots.1.is_active', false)
            ->assertJsonCount(1, 'data.app_booking.slots');

        // An empty value clears them, as the app sends when none are left.
        $this->post($url, ['name' => 'Archana', 'kind' => 'seva', 'is_free' => '1', 'slots' => ''], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonCount(0, 'data.raw.slots');
    }
}
