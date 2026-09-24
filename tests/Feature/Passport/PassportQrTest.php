<?php

namespace Tests\Feature\Passport;

use App\Enums\CheckInMethod;
use App\Enums\TempleStatus;
use App\Enums\UserRole;
use App\Filament\Pages\ScanDevoteePassport;
use App\Filament\Temple\Pages\ScanPassport;
use App\Models\Devotee;
use App\Models\DevoteeVisit;
use App\Models\Temple;
use App\Models\TempleUser;
use App\Models\User;
use App\Support\PassportQr;
use App\Support\StaffCheckIn;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A devotee's own passport code: other devotees, staff and temple counters
 * scan it to see the passport, and a temple's own staff can mark today's
 * visit from it.
 */
class PassportQrTest extends TestCase
{
    use RefreshDatabase;

    protected function temple(string $name = 'Sri Ranganathaswamy Temple', array $attributes = []): Temple
    {
        return Temple::create(array_merge(['name' => $name, 'status' => TempleStatus::Published, 'city' => 'Srirangam'], $attributes));
    }

    protected function templeAdmin(Temple ...$temples): User
    {
        $user = User::factory()->create(['role' => UserRole::TempleAdmin, 'is_active' => true]);

        foreach ($temples as $temple) {
            TempleUser::create(['temple_id' => $temple->id, 'user_id' => $user->id, 'requested_at' => now(), 'approved_at' => now()]);
        }

        return $user;
    }

    // --- The code itself ---

    public function test_every_devotee_gets_a_random_code_that_is_not_their_id(): void
    {
        $a = Devotee::factory()->create();
        $b = Devotee::factory()->create();

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{20}$/', $a->passport_code);
        $this->assertNotSame($a->passport_code, $b->passport_code);
        $this->assertStringNotContainsString('/passport/'.$a->id, PassportQr::url($a));
    }

    public function test_a_code_is_read_from_the_url_the_app_scheme_or_bare(): void
    {
        $devotee = Devotee::factory()->create();
        $code = $devotee->passport_code;

        foreach ([PassportQr::url($devotee), "templepassport://passport/{$code}", $code, "  {$code}  "] as $form) {
            $this->assertTrue(Devotee::findByPassportCode($form)?->is($devotee), $form);
        }

        $this->assertNull(PassportQr::parse('https://example.com/temples/some-temple/checkin?s=abc'));
        $this->assertNull(PassportQr::parse('templepassport://devotee/1'));
    }

    public function test_the_code_is_in_the_devotees_own_profile_and_can_be_reset(): void
    {
        $devotee = Devotee::factory()->create();
        Sanctum::actingAs($devotee, guard: 'devotee');

        $old = $this->getJson('/api/v1/me')->assertOk()->json('data.passport_url');
        $this->assertSame(PassportQr::url($devotee), $old);
        $this->assertSame($old, $this->getJson('/api/v1/me/passport/qr')->json('data.url'));

        $before = $devotee->passport_code;
        $new = $this->postJson('/api/v1/me/passport/qr/reset')->assertOk()->json('data.code');

        $this->assertNotSame($before, $new);
        $this->getJson('/api/v1/passports/'.$before)->assertNotFound();
        $this->getJson('/api/v1/passports/'.$new)->assertOk();
    }

    // --- What a scan shows ---

    public function test_a_scanned_passport_shows_public_visits_and_nothing_private(): void
    {
        $temple = $this->temple();
        $hidden = $this->temple('A Private Pilgrimage');
        $devotee = Devotee::factory()->create(['email' => 'secret@example.com', 'phone' => '9999999999']);
        DevoteeVisit::create(['devotee_id' => $devotee->id, 'temple_id' => $temple->id, 'method' => 'qr', 'visited_on' => '2026-01-10', 'is_verified' => true, 'note' => 'my private note']);
        DevoteeVisit::create(['devotee_id' => $devotee->id, 'temple_id' => $hidden->id, 'visited_on' => '2026-02-10', 'is_public' => false]);

        $response = $this->getJson('/api/v1/passports/'.$devotee->passport_code)->assertOk();

        $response->assertJsonPath('data.name', $devotee->name)
            ->assertJsonPath('data.stamps', 1)
            ->assertJsonPath('data.visits_recorded', 1)
            ->assertJsonPath('data.visits.0.temple.name', 'Sri Ranganathaswamy Temple')
            ->assertJsonPath('data.visits.0.is_verified', true);

        $body = $response->getContent();
        foreach (['secret@example.com', '9999999999', 'my private note', 'A Private Pilgrimage', 'passport_code'] as $leak) {
            $this->assertStringNotContainsString($leak, $body);
        }
    }

    public function test_an_unknown_or_deactivated_code_is_not_found(): void
    {
        $this->getJson('/api/v1/passports/'.str_repeat('a', 20))->assertNotFound();

        $devotee = Devotee::factory()->create(['is_active' => false]);
        $this->getJson('/api/v1/passports/'.$devotee->passport_code)->assertNotFound();
    }

    public function test_a_phone_camera_opens_a_readable_page(): void
    {
        $devotee = Devotee::factory()->create(['name' => 'Meera Devi']);

        $this->get('/passport/'.$devotee->passport_code)->assertOk()->assertSee('Meera Devi');
        $this->get('/passport/'.str_repeat('b', 20))->assertOk()->assertSee('Passport not found');
    }

    // --- Temple staff marking a visit ---

    public function test_temple_staff_mark_a_verified_visit_at_their_own_temple(): void
    {
        $temple = $this->temple();
        $devotee = Devotee::factory()->create();
        $staff = $this->templeAdmin($temple);

        $result = StaffCheckIn::mark($devotee, $temple, $staff);

        $this->assertSame(StaffCheckIn::CREATED, $result['outcome']);
        $visit = $result['visit']->fresh();
        $this->assertSame(CheckInMethod::Staff, $visit->method);
        $this->assertTrue($visit->is_verified);
        $this->assertSame($staff->id, $visit->verified_by);
        $this->assertSame(1, $devotee->stampCount());
    }

    public function test_a_second_scan_the_same_day_adds_nothing(): void
    {
        $temple = $this->temple();
        $devotee = Devotee::factory()->create();
        $staff = $this->templeAdmin($temple);

        StaffCheckIn::mark($devotee, $temple, $staff);
        $again = StaffCheckIn::mark($devotee, $temple, $staff);

        $this->assertSame(StaffCheckIn::ALREADY, $again['outcome']);
        $this->assertSame(1, DevoteeVisit::count());
    }

    public function test_a_self_recorded_visit_today_is_verified_rather_than_doubled(): void
    {
        $temple = $this->temple();
        $devotee = Devotee::factory()->create();
        $own = DevoteeVisit::create(['devotee_id' => $devotee->id, 'temple_id' => $temple->id, 'visited_on' => \App\Support\DevotionalClock::now()->toDateString()]);

        $result = StaffCheckIn::mark($devotee, $temple, $this->templeAdmin($temple));

        $this->assertSame(StaffCheckIn::VERIFIED, $result['outcome']);
        $this->assertTrue($own->fresh()->is_verified);
        $this->assertSame(1, DevoteeVisit::count());
    }

    public function test_staff_cannot_mark_a_visit_at_another_temple(): void
    {
        $mine = $this->temple('Mine');
        $theirs = $this->temple('Theirs');

        $this->expectException(AuthorizationException::class);

        StaffCheckIn::mark(Devotee::factory()->create(), $theirs, $this->templeAdmin($mine));
    }

    public function test_the_app_cannot_claim_a_staff_marked_visit(): void
    {
        $temple = $this->temple();
        Sanctum::actingAs(Devotee::factory()->create(), guard: 'devotee');

        $this->postJson("/api/v1/temples/{$temple->slug}/visits", ['method' => 'staff'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('method');
    }

    // --- The panels ---

    public function test_the_temple_portal_scans_and_marks_from_the_page(): void
    {
        $temple = $this->temple();
        $other = $this->temple('Not Theirs');
        $devotee = Devotee::factory()->create(['name' => 'Arjun Rao']);
        $this->actingAs($this->templeAdmin($temple));

        Livewire::test(ScanPassport::class)
            ->call('scan', PassportQr::url($devotee))
            ->assertSee('Arjun Rao')
            ->assertSee('Mark visited today')
            ->call('markVisited', $other->id)
            ->call('markVisited', $temple->id)
            ->assertSee('Stamped today');

        $this->assertSame([$temple->id], DevoteeVisit::pluck('temple_id')->all());
    }

    public function test_the_portal_page_refuses_a_temple_code_and_keeps_no_id(): void
    {
        $temple = $this->temple();
        $this->actingAs($this->templeAdmin($temple));

        Livewire::test(ScanPassport::class)
            ->call('scan', \App\Support\TempleQr::url($temple))
            ->assertSet('scannedCode', null)
            ->assertSee('not a devotee passport code');
    }

    public function test_the_portal_page_is_for_temple_admins_and_the_admin_page_for_staff(): void
    {
        $this->actingAs($this->templeAdmin($this->temple()))->get('/temple/scan-passport')->assertOk();

        $admin = User::factory()->create(['role' => UserRole::SuperAdmin, 'is_active' => true]);
        $this->actingAs($admin)->get('/admin/scan-passport')->assertOk();
        $this->actingAs($admin)->get('/temple/scan-passport')->assertForbidden();
    }

    public function test_staff_scan_links_to_the_devotee_record_without_marking(): void
    {
        $devotee = Devotee::factory()->create(['name' => 'Lakshmi Iyer']);
        $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin, 'is_active' => true]));

        Livewire::test(ScanDevoteePassport::class)
            ->call('scan', $devotee->passport_code)
            ->assertSee('Lakshmi Iyer')
            ->assertSee('Open devotee record')
            ->assertDontSee('Mark visited today');
    }
}
