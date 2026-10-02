<?php

namespace Tests\Feature\Api\V1\Trust;

use App\Enums\BookingStatus;
use App\Enums\EventStatus;
use App\Enums\TempleStatus;
use App\Enums\TempleSuggestionStatus;
use App\Enums\UserRole;
use App\Models\Devotee;
use App\Models\District;
use App\Models\PujaBooking;
use App\Models\State;
use App\Models\Temple;
use App\Models\TempleEvent;
use App\Models\TemplePuja;
use App\Models\TempleSuggestion;
use App\Models\TempleUser;
use App\Models\User;
use App\Support\DevotionalClock;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The temple trust app: a temple's own team signs up, asks for its temple
 * (or registers it), and manages it once staff approve — never another one.
 */
class TrustAppApiTest extends TestCase
{
    use RefreshDatabase;

    protected const JSON = ['Accept' => 'application/json'];

    protected Temple $temple;

    protected Temple $other;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('filesystems.media'));

        $this->temple = Temple::create(['name' => 'Sri Rama Temple', 'slug' => 'sri-rama', 'city' => 'Bhadrachalam', 'status' => TempleStatus::Published, 'published_at' => now()]);
        $this->other = Temple::create(['name' => 'Another Temple', 'slug' => 'another', 'status' => TempleStatus::Published, 'published_at' => now()]);
    }

    protected function register(array $overrides = []): string
    {
        return $this->postJson('/api/v1/trust/auth/register', array_merge([
            'name' => 'Trust Secretary',
            'email' => 'secretary@example.org',
            'phone' => '9876543210',
            'password' => 'a-long-password',
        ], $overrides))->assertCreated()->json('data.token');
    }

    /** A signed-in team member whose claim on $this->temple is approved. */
    protected function manager(): array
    {
        $token = $this->register();
        $user = User::query()->where('email', 'secretary@example.org')->firstOrFail();

        TempleUser::create([
            'temple_id' => $this->temple->id, 'user_id' => $user->id, 'role' => 'owner',
            'requested_at' => now(), 'approved_at' => now(),
        ]);

        return [$token, $user];
    }

    protected function as(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    public function test_signing_up_makes_a_temple_admin_with_no_temples(): void
    {
        $response = $this->postJson('/api/v1/trust/auth/register', [
            'name' => 'Trust Secretary',
            'email' => 'Secretary@Example.org',
            'phone' => '9876543210',
            'password' => 'a-long-password',
            // Ignored: nothing a caller sends can choose the role.
            'role' => 'super_admin',
            'is_active' => false,
        ])->assertCreated()
            ->assertJsonPath('data.account.user.email', 'secretary@example.org')
            ->assertJsonCount(0, 'data.account.temples');

        $user = User::query()->firstOrFail();
        $this->assertSame(UserRole::TempleAdmin, $user->role);
        $this->assertTrue($user->is_active);
        $this->assertSame([], $user->approvedTempleIds());

        $this->as($response->json('data.token'))->getJson('/api/v1/trust/temples/'.$this->temple->id)->assertNotFound();
    }

    public function test_login_is_for_temple_teams_only(): void
    {
        $this->register();

        $this->postJson('/api/v1/trust/auth/login', ['email' => 'SECRETARY@example.org', 'password' => 'a-long-password'])
            ->assertOk()
            ->assertJsonStructure(['data' => ['token', 'account' => ['user', 'temples', 'claims', 'registrations']]]);

        $this->postJson('/api/v1/trust/auth/login', ['email' => 'secretary@example.org', 'password' => 'wrong'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        User::create(['name' => 'Editor', 'email' => 'editor@example.org', 'password' => 'a-long-password', 'role' => UserRole::Editor]);
        $this->postJson('/api/v1/trust/auth/login', ['email' => 'editor@example.org', 'password' => 'a-long-password'])
            ->assertUnprocessable();

        User::create(['name' => 'Admin', 'email' => 'admin@example.org', 'password' => 'a-long-password', 'role' => UserRole::SuperAdmin]);
        $this->postJson('/api/v1/trust/auth/login', ['email' => 'admin@example.org', 'password' => 'a-long-password'])
            ->assertOk()
            ->assertJsonPath('data.account.user.is_super_admin', true);
    }

    public function test_a_devotee_token_is_refused_and_a_deactivated_team_is_stopped(): void
    {
        $devotee = Devotee::factory()->create();
        $this->as($devotee->createToken('app')->plainTextToken)->getJson('/api/v1/trust/me')->assertUnauthorized();

        [$token, $user] = $this->manager();
        $this->as($token)->getJson('/api/v1/trust/me')->assertOk()->assertJsonCount(1, 'data.temples');

        $user->update(['is_active' => false]);
        $this->as($token)->getJson('/api/v1/trust/me')->assertForbidden();
    }

    public function test_a_claim_waits_for_staff_and_grants_nothing_until_approved(): void
    {
        $token = $this->register();

        $this->as($token)->getJson('/api/v1/trust/claimable-temples?q=Rama')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Sri Rama Temple')
            ->assertJsonPath('data.0.claim_status', null);

        $this->as($token)->postJson('/api/v1/trust/claims', [
            'temple_id' => $this->temple->id, 'role' => 'owner', 'note' => 'I am the secretary of the temple trust.', 'latitude' => 17.6936, 'longitude' => 78.9686, 'location_accuracy' => 12,
        ])->assertCreated()->assertJsonPath('data.status', 'pending');

        $this->as($token)->postJson('/api/v1/trust/claims', [
            'temple_id' => $this->temple->id, 'role' => 'owner', 'note' => 'Asking again, just in case.', 'latitude' => 17.6936, 'longitude' => 78.9686, 'location_accuracy' => 12,
        ])->assertUnprocessable();

        $this->as($token)->getJson('/api/v1/trust/temples/'.$this->temple->id)->assertNotFound();

        TempleUser::query()->update(['approved_at' => now()]);

        $this->as($token)->getJson('/api/v1/trust/temples/'.$this->temple->id)
            ->assertOk()
            ->assertJsonPath('data.name', 'Sri Rama Temple')
            ->assertJsonStructure(['data' => ['profile', 'stats' => ['bookings_today', 'events_in_review', 'reviews_to_answer']]]);
    }

    public function test_asking_to_manage_a_temple_needs_a_live_fix_at_the_temple(): void
    {
        $this->temple->update(['latitude' => 17.6800000, 'longitude' => 80.8900000]);
        $token = $this->register();
        $ask = fn (array $gps) => $this->as($token)->postJson('/api/v1/trust/claims', [
            'temple_id' => $this->temple->id, 'role' => 'owner', 'note' => 'I am the secretary of the temple trust.',
        ] + $gps);

        $ask([])->assertUnprocessable()->assertJsonValidationErrors(['latitude', 'longitude', 'location_accuracy']);

        // Too vague, and from Hyderabad: both refused.
        $ask(['latitude' => 17.6801, 'longitude' => 80.8901, 'location_accuracy' => 900])
            ->assertUnprocessable()->assertJsonValidationErrors('location_accuracy');
        $ask(['latitude' => 17.3850, 'longitude' => 78.4867, 'location_accuracy' => 8])
            ->assertUnprocessable()->assertJsonValidationErrors('latitude');

        // Standing at the gate, ~30 m away.
        $ask(['latitude' => 17.6802, 'longitude' => 80.8902, 'location_accuracy' => 8])->assertCreated();

        $claim = TempleUser::query()->sole();
        $this->assertEqualsWithDelta(30, $claim->claim_distance_m, 5);
        $this->assertSame(8, $claim->claim_accuracy_m);
        $this->assertStringStartsWith('At the temple', $claim->claimLocationSummary());
    }

    public function test_a_missing_temple_is_registered_and_handed_back_as_a_pending_claim(): void
    {
        $token = $this->register();

        $this->as($token)->post('/api/v1/trust/registrations', [
            'name' => 'Sri Someshwara Swamy Temple',
            'city' => 'Kolanupaka',
            'description' => 'A small Chalukya-era Shiva temple cared for by the village trust.',
            'submitter_role' => 'trustee',
            'photos' => [UploadedFile::fake()->image('front.jpg')],
        ], self::JSON)->assertUnprocessable()->assertJsonValidationErrors(['latitude', 'location_accuracy']);

        // Taken at the temple, but too vague a fix.
        $this->as($token)->post('/api/v1/trust/registrations', [
            'name' => 'Sri Someshwara Swamy Temple', 'city' => 'Kolanupaka',
            'description' => 'A small Chalukya-era Shiva temple cared for by the village trust.',
            'submitter_role' => 'trustee', 'photos' => [UploadedFile::fake()->image('front.jpg')],
            'latitude' => 17.6936, 'longitude' => 78.9686, 'location_accuracy' => 900,
        ], self::JSON)->assertUnprocessable()->assertJsonValidationErrors('location_accuracy');

        $this->as($token)->post('/api/v1/trust/registrations', [
            'name' => 'Sri Someshwara Swamy Temple',
            'city' => 'Kolanupaka',
            'description' => 'A small Chalukya-era Shiva temple cared for by the village trust.',
            'submitter_role' => 'trustee',
            'photos' => [UploadedFile::fake()->image('front.jpg')],
            'latitude' => 17.6936, 'longitude' => 78.9686, 'location_accuracy' => 12,
        ], self::JSON)->assertCreated()->assertJsonPath('data.status.value', 'pending');

        // A devotee's role is not a temple team's.
        $this->as($token)->post('/api/v1/trust/registrations', [
            'name' => 'Another', 'city' => 'X', 'description' => str_repeat('a', 30),
            'submitter_role' => 'devotee', 'photos' => [UploadedFile::fake()->image('a.jpg')],
        ], self::JSON)->assertUnprocessable()->assertJsonValidationErrors('submitter_role');

        $suggestion = TempleSuggestion::query()->firstOrFail();
        $this->assertSame('9876543210', $suggestion->submitter_phone, 'the account phone stands in');

        $staff = User::create(['name' => 'Admin', 'email' => 'admin@example.org', 'password' => 'x', 'role' => UserRole::SuperAdmin]);
        $temple = $suggestion->createTemple($staff->id);

        $claim = TempleUser::query()->where('temple_id', $temple->id)->firstOrFail();
        $this->assertSame('pending', $claim->status());
        $this->assertSame(TempleSuggestionStatus::Approved, $suggestion->refresh()->status);

        $this->as($token)->getJson('/api/v1/trust/me')
            ->assertJsonPath('data.claims.0.status', 'pending')
            ->assertJsonPath('data.registrations.0.temple_id', $temple->id);
    }

    public function test_the_address_carries_state_and_district_and_reloads_fresh(): void
    {
        [$token] = $this->manager();
        $state = State::create(['name' => 'Telangana', 'slug' => 'telangana', 'code' => 'TG', 'type' => 'state']);

        $this->as($token)->patchJson('/api/v1/trust/temples/'.$this->temple->id, [
            'address' => 'Temple Street', 'state_id' => $state->id, 'district' => 'bhadradri kothagudem',
        ])->assertOk()
            ->assertJsonPath('data.profile.state', 'Telangana')
            ->assertJsonPath('data.profile.district', 'Bhadradri Kothagudem');

        // Same district again is matched, not duplicated.
        $this->as($token)->patchJson('/api/v1/trust/temples/'.$this->temple->id, ['state_id' => $state->id, 'district' => 'Bhadradri Kothagudem'])->assertOk();
        $this->assertSame(1, District::query()->count());

        $res = $this->as($token)->getJson('/api/v1/trust/temples/'.$this->temple->id)
            ->assertOk()
            ->assertJsonPath('data.profile.address', 'Temple Street')
            ->assertJsonPath('data.profile.district', 'Bhadradri Kothagudem');

        // No proxy or CDN may keep an API answer.
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));
        $this->assertSame('no-store', $res->headers->get('CDN-Cache-Control'));
    }

    public function test_the_team_edits_its_own_listing_but_not_its_identity_or_another_temple(): void
    {
        [$token] = $this->manager();

        $this->as($token)->patchJson('/api/v1/trust/temples/'.$this->temple->id, [
            'contact_phone' => '08743 232428',
            'dress_code' => 'Traditional attire',
            'name' => 'Renamed',
            'verification_status' => 'official',
        ])->assertOk()->assertJsonPath('data.profile.contact_phone', '08743 232428');

        // A new position comes from the phone at the temple, with its accuracy.
        $this->as($token)->patchJson('/api/v1/trust/temples/'.$this->temple->id, ['latitude' => 17.67, 'longitude' => 80.89])
            ->assertUnprocessable()->assertJsonValidationErrors('location_accuracy');
        $this->as($token)->patchJson('/api/v1/trust/temples/'.$this->temple->id, ['latitude' => 17.67, 'longitude' => 80.89, 'location_accuracy' => 8])
            ->assertOk();

        $this->temple->refresh();
        $this->assertSame('Sri Rama Temple', $this->temple->name);
        $this->assertNotSame('official', $this->temple->verification_status?->value);

        $this->as($token)->patchJson('/api/v1/trust/temples/'.$this->other->id, ['dress_code' => 'x'])->assertNotFound();
    }

    public function test_the_portal_team_may_update_its_published_temple_but_not_publish_or_rename_it(): void
    {
        [, $user] = $this->manager();
        $this->actingAs($user);

        $this->temple->update(['contact_phone' => '040 1234 5678']);
        $this->assertSame('040 1234 5678', $this->temple->refresh()->contact_phone);

        try {
            $this->temple->refresh()->update(['name' => 'Renamed']);
            $this->fail('A temple team renamed a published temple.');
        } catch (AuthorizationException) {
            $this->assertSame('Sri Rama Temple', $this->temple->refresh()->name);
        }

        // A draft they manage stays a draft: publishing is the editors'.
        $draft = Temple::create(['name' => 'Village Temple', 'status' => TempleStatus::Draft]);
        TempleUser::create(['temple_id' => $draft->id, 'user_id' => $user->id, 'role' => 'owner', 'approved_at' => now()]);

        try {
            $draft->update(['status' => TempleStatus::Published]);
            $this->fail('A temple team published its own temple.');
        } catch (AuthorizationException) {
            $this->assertSame(TempleStatus::Draft, $draft->refresh()->status);
        }

        // Not their temple: still refused.
        $this->expectException(AuthorizationException::class);
        $this->other->update(['contact_phone' => '040 1234 5678']);
    }

    public function test_timings_and_closures_are_managed_within_the_temple(): void
    {
        [$token] = $this->manager();
        $base = '/api/v1/trust/temples/'.$this->temple->id;

        $id = $this->as($token)->postJson($base.'/timings', [
            'kind' => 'darshan', 'opens_at' => '06:00', 'closes_at' => '12:30',
        ])->assertCreated()->assertJsonPath('data.window', '06:00 – 12:30')->json('data.id');

        $this->as($token)->putJson($base.'/timings/'.$id, ['kind' => 'darshan', 'opens_at' => '05:30', 'closes_at' => '12:30', 'day_of_week' => 1])
            ->assertOk()->assertJsonPath('data.day_of_week', 1);

        // Another temple's URL does not reach this temple's rows.
        $this->as($token)->deleteJson('/api/v1/trust/temples/'.$this->other->id.'/timings/'.$id)->assertNotFound();

        $this->as($token)->postJson($base.'/closures', [
            'reason' => 'Grahanam', 'starts_on' => now()->addDay()->toDateString(), 'is_full_day' => true, 'opens_at' => '06:00',
        ])->assertCreated()->assertJsonPath('data.opens_at', null);

        $this->as($token)->getJson($base.'/timings')->assertJsonCount(1, 'data');
        $this->as($token)->deleteJson($base.'/timings/'.$id)->assertOk();
    }

    public function test_an_event_the_team_publishes_waits_for_review_unless_the_temple_is_verified(): void
    {
        [$token, $user] = $this->manager();

        $this->as($token)->post('/api/v1/trust/temples/'.$this->temple->id.'/events', [
            'type' => 'festival',
            'title' => 'Sri Rama Navami',
            'starts_on' => now()->addWeek()->toDateString(),
            'is_all_day' => '1',
            'status' => 'published',
            'image' => UploadedFile::fake()->image('navami.jpg'),
        ], self::JSON)->assertCreated()
            ->assertJsonPath('data.status.value', EventStatus::PendingReview->value);

        $event = TempleEvent::query()->firstOrFail();
        $this->assertSame($user->id, $event->created_by);
        $this->assertNotNull($event->image_path);
        Storage::disk(config('filesystems.media'))->assertExists($event->image_path);

        // A team cannot set review states itself.
        $this->as($token)->post('/api/v1/trust/temples/'.$this->temple->id.'/events/'.$event->id, [
            'type' => 'festival', 'title' => 'x', 'starts_on' => now()->toDateString(), 'is_all_day' => '1', 'status' => 'pending_review',
        ], self::JSON)->assertUnprocessable();
    }

    public function test_sevas_keep_their_fee_rules_and_bookings_are_received_once(): void
    {
        [$token] = $this->manager();
        $base = '/api/v1/trust/temples/'.$this->temple->id;

        $this->as($token)->post($base.'/sevas', [
            'kind' => 'seva', 'name' => 'Abhishekam', 'is_free' => '0', 'app_booking_enabled' => '1',
        ], self::JSON)->assertCreated()->assertJsonPath('data.raw.app_booking_enabled', false);

        $pujaId = $this->as($token)->post($base.'/sevas', [
            'kind' => 'seva', 'name' => 'Archana', 'is_free' => '1', 'app_booking_enabled' => '1',
        ], self::JSON)->assertCreated()->assertJsonPath('data.raw.app_booking_enabled', true)->json('data.id');

        $booking = PujaBooking::create([
            'temple_id' => $this->temple->id,
            'temple_puja_id' => $pujaId,
            'devotee_id' => Devotee::factory()->create()->id,
            'booked_for' => DevotionalClock::now()->toDateString(),
            'people' => 2,
            'devotee_name' => 'Anu',
            'amount_paise' => 0,
            'status' => BookingStatus::Confirmed,
            'confirmed_at' => now(),
        ]);

        $this->as($token)->getJson($base.'/bookings?date='.DevotionalClock::now()->toDateString())->assertJsonCount(1, 'data');

        $this->as($token)->postJson('/api/v1/trust/bookings/scan', ['code' => $booking->reference])
            ->assertOk()->assertJsonPath('data.outcome', null);
        $this->as($token)->postJson('/api/v1/trust/bookings/verify', ['code' => $booking->reference])
            ->assertOk()->assertJsonPath('data.outcome', 'verified');
        $this->as($token)->postJson('/api/v1/trust/bookings/verify', ['code' => $booking->reference])
            ->assertOk()->assertJsonPath('data.outcome', 'already_verified');

        // A seva with bookings is unpublished, not deleted.
        $this->as($token)->deleteJson($base.'/sevas/'.$pujaId)->assertUnprocessable();
    }

    public function test_another_temples_booking_reads_as_unknown(): void
    {
        [$token] = $this->manager();
        $puja = TemplePuja::create(['temple_id' => $this->other->id, 'name' => 'Archana', 'is_free' => true, 'app_booking_enabled' => true]);

        $booking = PujaBooking::create([
            'temple_id' => $this->other->id, 'temple_puja_id' => $puja->id,
            'devotee_id' => Devotee::factory()->create()->id,
            'booked_for' => now()->toDateString(), 'people' => 1, 'devotee_name' => 'Anu', 'amount_paise' => 0,
            'status' => BookingStatus::Confirmed,
        ]);

        $this->as($token)->postJson('/api/v1/trust/bookings/verify', ['code' => $booking->reference])->assertNotFound();
        $this->assertSame(BookingStatus::Confirmed, $booking->refresh()->status);
    }

    public function test_photos_upload_and_the_options_list_loads(): void
    {
        [$token] = $this->manager();

        $this->as($token)->post('/api/v1/trust/temples/'.$this->temple->id.'/photos', [
            'photo' => UploadedFile::fake()->image('gopuram.jpg', 1600, 1000),
            'category' => 'exterior',
            'caption' => 'Rajagopuram',
        ], self::JSON)->assertCreated()->assertJsonPath('data.is_primary', true);

        $this->as($token)->getJson('/api/v1/trust/options')
            ->assertOk()
            ->assertJsonStructure(['data' => ['timing_kinds', 'event_types', 'puja_kinds', 'photo_categories', 'days', 'claim_levels', 'registration_roles', 'deities', 'states']]);
    }

    protected function superAdmin(): string
    {
        User::create(['name' => 'Admin', 'email' => 'admin@example.org', 'password' => 'a-long-password', 'role' => UserRole::SuperAdmin]);

        return $this->postJson('/api/v1/trust/auth/login', ['email' => 'admin@example.org', 'password' => 'a-long-password'])->json('data.token');
    }

    public function test_a_super_admin_manages_every_temple_and_the_team_cannot_reach_the_admin_queues(): void
    {
        $admin = $this->superAdmin();

        $this->as($admin)->getJson('/api/v1/trust/temples?q=Another')->assertOk()->assertJsonPath('data.0.name', 'Another Temple');
        $this->as($admin)->patchJson('/api/v1/trust/temples/'.$this->other->id, ['contact_phone' => '0401234'])->assertOk();
        $this->as($admin)->postJson('/api/v1/trust/temples/'.$this->other->id.'/timings', ['kind' => 'darshan', 'opens_at' => '06:00'])->assertCreated();

        // Super admins publish events directly.
        $this->as($admin)->post('/api/v1/trust/temples/'.$this->other->id.'/events', [
            'type' => 'festival', 'title' => 'Utsavam', 'starts_on' => now()->addDay()->toDateString(), 'is_all_day' => '1', 'status' => 'published',
        ], self::JSON)->assertCreated()->assertJsonPath('data.status.value', 'published');

        $this->as($admin)->postJson('/api/v1/trust/claims', ['temple_id' => $this->temple->id, 'role' => 'owner', 'note' => 'Just checking this.'])
            ->assertUnprocessable();

        $team = $this->register();
        $this->as($team)->getJson('/api/v1/trust/admin/overview')->assertForbidden();
        $this->as($team)->getJson('/api/v1/trust/temples/'.$this->other->id)->assertNotFound();
    }

    public function test_a_super_admin_approves_claims_registrations_and_events_from_the_app(): void
    {
        $team = $this->register();
        $this->as($team)->postJson('/api/v1/trust/claims', [
            'temple_id' => $this->temple->id, 'role' => 'owner', 'note' => 'Secretary of the temple trust.', 'latitude' => 17.6936, 'longitude' => 78.9686, 'location_accuracy' => 12,
        ])->assertCreated();
        $this->as($team)->post('/api/v1/trust/registrations', [
            'name' => 'Village Shiva Temple', 'city' => 'Kolanupaka',
            'description' => 'A small Chalukya-era Shiva temple cared for by the village trust.',
            'submitter_role' => 'trustee', 'photos' => [UploadedFile::fake()->image('front.jpg')],
            'latitude' => 17.6936, 'longitude' => 78.9686, 'location_accuracy' => 10,
        ], self::JSON)->assertCreated();

        $admin = $this->superAdmin();
        $this->as($admin)->getJson('/api/v1/trust/admin/overview')
            ->assertJsonPath('data.claims_pending', 1)
            ->assertJsonPath('data.registrations_pending', 1);

        $claimId = $this->as($admin)->getJson('/api/v1/trust/admin/claims')
            ->assertJsonPath('data.0.user.phone', '9876543210')
            ->json('data.0.id');
        $this->as($admin)->postJson('/api/v1/trust/admin/claims/'.$claimId.'/approve')->assertOk()->assertJsonPath('data.status', 'approved');

        $regId = $this->as($admin)->getJson('/api/v1/trust/admin/registrations')->assertJsonPath('data.0.from_trust_app', true)->json('data.0.id');
        $templeId = $this->as($admin)->postJson('/api/v1/trust/admin/registrations/'.$regId.'/approve')->assertOk()->json('data.created_temple_id');
        $this->as($admin)->postJson('/api/v1/trust/admin/registrations/'.$regId.'/reject', ['note' => 'x'])->assertUnprocessable();

        // The registrant now waits on a claim for the new temple, which is a draft.
        $this->assertSame('pending', TempleUser::query()->where('temple_id', $templeId)->firstOrFail()->status());
        $this->assertSame(TempleStatus::Draft, Temple::findOrFail($templeId)->status);
        $this->as($admin)->patchJson('/api/v1/trust/admin/temples/'.$templeId.'/status', ['status' => 'published'])
            ->assertOk()->assertJsonPath('data.status.value', 'published');

        // The team's event waits; the super admin approves it.
        $this->as($team)->post('/api/v1/trust/temples/'.$this->temple->id.'/events', [
            'type' => 'festival', 'title' => 'Sri Rama Navami', 'starts_on' => now()->addWeek()->toDateString(), 'is_all_day' => '1', 'status' => 'published',
        ], self::JSON)->assertCreated()->assertJsonPath('data.status.value', 'pending_review');

        $eventId = $this->as($admin)->getJson('/api/v1/trust/admin/events')->assertJsonCount(1, 'data')->json('data.0.id');
        $this->as($admin)->postJson('/api/v1/trust/admin/events/'.$eventId.'/approve')->assertOk()->assertJsonPath('data.status.value', 'published');
    }
}
