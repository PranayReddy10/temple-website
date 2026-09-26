<?php

namespace Tests\Feature\Api\V1;

use App\Enums\SevaDriveStatus;
use App\Enums\TempleStatus;
use App\Models\Devotee;
use App\Models\SevaDrive;
use App\Models\SevaDriveMedia;
use App\Models\Temple;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Seva drives: raised with photographs, approved before anybody sees them,
 * joined by volunteers, closed with after-photographs, and verified before
 * they may ask for money.
 */
class SevaDriveApiTest extends TestCase
{
    use RefreshDatabase;

    protected const JSON = ['Accept' => 'application/json'];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.media'));
    }

    /** @return array<string, mixed> */
    protected function details(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Clean the old Shiva temple at Kolanupaka',
            'cause' => 'cleaning',
            'place_name' => 'Old Someshwara shrine',
            'city' => 'Kolanupaka',
            'problem' => 'Weeds have covered the steps and the mandapam is full of litter.',
            'plan' => 'Clear the weeds, sweep the mandapam and carry the litter away in bags.',
            'what_to_bring' => 'Gloves, a broom, water',
            'starts_at' => now()->addWeek()->setTime(7, 0)->toIso8601String(),
            'volunteers_needed' => 20,
            'contact_phone' => '+91 98480 12345',
            'upi_id' => 'seva.organiser@okaxis',
            'upi_name' => 'Ravi Kumar',
            'donation_goal' => 5000,
        ], $overrides);
    }

    protected function raise(Devotee $organiser, array $overrides = []): SevaDrive
    {
        Sanctum::actingAs($organiser, guard: 'devotee');

        $id = $this->post('/api/v1/me/seva-drives', [
            ...$this->details($overrides),
            'photos' => [UploadedFile::fake()->image('before.jpg')],
        ], self::JSON)->assertCreated()->json('data.id');

        return SevaDrive::findOrFail($id);
    }

    public function test_a_new_drive_is_listed_at_once_as_not_verified(): void
    {
        $organiser = Devotee::factory()->create();

        $drive = $this->raise($organiser, ['video_url' => 'https://youtu.be/abc123']);

        $this->assertSame(SevaDriveStatus::Approved, $drive->status);
        $this->assertSame(2, $drive->media()->where('stage', 'before')->count());
        $this->assertSame($organiser->id, $drive->devotee_id);

        Sanctum::actingAs(Devotee::factory()->create(), guard: 'devotee');
        $this->getJson('/api/v1/seva-drives')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/seva-drives/{$drive->id}")
            ->assertOk()
            ->assertJsonPath('data.is_verified', false)
            ->assertJsonPath('data.status.value', 'approved')
            ->assertJsonPath('data.viewer.can_join', true);
    }

    public function test_with_approval_switched_on_a_new_drive_waits_for_review(): void
    {
        \App\Models\Setting::set('seva_requires_approval', '1', 'boolean');

        $drive = $this->raise(Devotee::factory()->create());

        $this->assertSame(SevaDriveStatus::Pending, $drive->status);

        Sanctum::actingAs(Devotee::factory()->create(), guard: 'devotee');
        $this->getJson('/api/v1/seva-drives')->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/seva-drives/{$drive->id}")->assertNotFound();
        $this->postJson("/api/v1/seva-drives/{$drive->id}/join")->assertNotFound();
    }

    public function test_a_drive_needs_a_photograph_of_the_place(): void
    {
        Sanctum::actingAs(Devotee::factory()->create(), guard: 'devotee');

        $this->post('/api/v1/me/seva-drives', $this->details(), self::JSON)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('photos');
    }

    public function test_a_devotee_cannot_approve_their_own_drive(): void
    {
        $drive = $this->raise(Devotee::factory()->create(), ['status' => 'completed', 'verified_at' => now()->toIso8601String(), 'donations_enabled' => true]);

        $this->assertSame(SevaDriveStatus::Approved, $drive->status);
        $this->assertFalse($drive->isVerified());
    }

    public function test_the_upi_id_is_served_only_once_the_work_is_verified(): void
    {
        $organiser = Devotee::factory()->create();
        $drive = $this->raise($organiser);
        $drive->forceFill(['status' => SevaDriveStatus::Approved])->save();

        $this->getJson("/api/v1/seva-drives/{$drive->id}")
            ->assertOk()
            ->assertJsonPath('data.donations.open', false)
            ->assertJsonPath('data.donations.upi_id', null);

        $drive->forceFill(['status' => SevaDriveStatus::Completed, 'verified_at' => now()])->save();

        $this->getJson("/api/v1/seva-drives/{$drive->id}")
            ->assertJsonPath('data.donations.open', true)
            ->assertJsonPath('data.donations.upi_id', 'seva.organiser@okaxis')
            ->assertJsonPath('data.is_verified', true);

        // Paused by staff: gone again.
        $drive->forceFill(['donations_enabled' => false])->save();

        $this->getJson("/api/v1/seva-drives/{$drive->id}")
            ->assertJsonPath('data.donations.upi_id', null)
            ->assertJsonPath('data.donations.upi_link', null);
    }

    public function test_volunteers_join_and_then_see_the_organisers_phone(): void
    {
        $drive = $this->raise(Devotee::factory()->create());
        $drive->forceFill(['status' => SevaDriveStatus::Approved])->save();

        $volunteer = Devotee::factory()->create();
        Sanctum::actingAs($volunteer, guard: 'devotee');

        $this->getJson("/api/v1/seva-drives/{$drive->id}")
            ->assertJsonPath('data.contact_phone', null)
            ->assertJsonPath('data.viewer.can_join', true);

        $this->postJson("/api/v1/seva-drives/{$drive->id}/join", ['party_size' => 3])
            ->assertOk()
            ->assertJsonPath('data.viewer.has_joined', true)
            ->assertJsonPath('data.volunteers_joined', 3)
            ->assertJsonPath('data.contact_phone', '+91 98480 12345');

        // Joining again changes the one sign-up rather than adding another.
        $this->postJson("/api/v1/seva-drives/{$drive->id}/join", ['party_size' => 2])
            ->assertJsonPath('data.volunteers_joined', 2)
            ->assertJsonPath('data.signups', 1);

        $this->getJson('/api/v1/me/seva-drives?scope=joined')->assertJsonCount(1, 'data');

        $this->deleteJson("/api/v1/seva-drives/{$drive->id}/join")
            ->assertJsonPath('data.viewer.has_joined', false)
            ->assertJsonPath('data.volunteers_joined', 0);
    }

    public function test_a_drive_whose_last_day_is_over_is_completed_and_closed_to_joining(): void
    {
        $drive = $this->raise(Devotee::factory()->create());

        Sanctum::actingAs(Devotee::factory()->create(), guard: 'devotee');
        $this->travelTo(now()->addWeeks(2));

        $this->getJson("/api/v1/seva-drives/{$drive->id}")
            ->assertJsonPath('data.status.value', 'completed')
            ->assertJsonPath('data.viewer.can_join', false);
        $this->postJson("/api/v1/seva-drives/{$drive->id}/join")->assertUnprocessable();

        $this->getJson('/api/v1/seva-drives')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/seva-drives?when=done')->assertJsonCount(1, 'data');
    }

    public function test_verifying_does_not_end_a_drive(): void
    {
        $drive = $this->raise(Devotee::factory()->create());
        $drive->verify(null);

        $volunteer = Devotee::factory()->create();
        Sanctum::actingAs($volunteer, guard: 'devotee');

        $this->postJson("/api/v1/seva-drives/{$drive->id}/join")
            ->assertOk()
            ->assertJsonPath('data.status.value', 'approved')
            ->assertJsonPath('data.is_verified', true)
            ->assertJsonPath('data.donations.open', true)
            // Verified and open: a volunteer can still back out.
            ->assertJsonPath('data.viewer.can_leave', true);

        $this->getJson('/api/v1/seva-drives')->assertJsonCount(1, 'data');
    }

    public function test_the_organiser_asks_for_verification(): void
    {
        $organiser = Devotee::factory()->create();
        $drive = $this->raise($organiser);

        $this->getJson("/api/v1/seva-drives/{$drive->id}")->assertJsonPath('data.viewer.can_request_verification', true);

        $this->postJson("/api/v1/me/seva-drives/{$drive->id}/request-verification", ['note' => 'The temple trust can confirm.'])
            ->assertOk()
            ->assertJsonPath('data.verification.requested', true)
            ->assertJsonPath('data.viewer.can_request_verification', false);

        $this->assertSame(1, SevaDrive::query()->verificationRequested()->count());
        $this->assertSame('The temple trust can confirm.', $drive->fresh()->verification_note);

        Sanctum::actingAs(Devotee::factory()->create(), guard: 'devotee');
        $this->postJson("/api/v1/me/seva-drives/{$drive->id}/request-verification")->assertNotFound();
    }

    public function test_the_organiser_closes_the_drive_with_after_photographs(): void
    {
        $organiser = Devotee::factory()->create();
        $drive = $this->raise($organiser);
        $drive->forceFill(['status' => SevaDriveStatus::Approved])->save();

        // Not before the day.
        $this->post("/api/v1/me/seva-drives/{$drive->id}/media", [
            'stage' => 'after',
            'photos' => [UploadedFile::fake()->image('after.jpg')],
        ], self::JSON)->assertUnprocessable()->assertJsonValidationErrors('stage');

        $this->travelTo(now()->addWeeks(2));

        $this->post("/api/v1/me/seva-drives/{$drive->id}/media", [
            'stage' => 'after',
            'photos' => [UploadedFile::fake()->image('after.jpg')],
        ], self::JSON)->assertCreated();

        $this->postJson("/api/v1/me/seva-drives/{$drive->id}/complete", [
            'completion_note' => 'Twenty-two of us cleared the steps and filled fourteen bags.',
        ])->assertOk()->assertJsonPath('data.status.value', 'completed');

        // Still not asking for money: staff have not verified it.
        $this->assertFalse($drive->fresh()->acceptsDonations());
    }

    public function test_an_approved_drive_keeps_its_place_and_plan(): void
    {
        $organiser = Devotee::factory()->create();
        $drive = $this->raise($organiser);
        $drive->forceFill(['status' => SevaDriveStatus::Approved])->save();

        $this->patchJson("/api/v1/me/seva-drives/{$drive->id}", [
            'place_name' => 'Somewhere else entirely',
            'meeting_point' => 'At the banyan tree by the east gate',
            'ends_at' => now()->addWeek()->setTime(11, 0)->toIso8601String(),
        ])->assertOk()->assertJsonPath('data.place.meeting_point', 'At the banyan tree by the east gate');

        $this->assertSame('Old Someshwara shrine', $drive->fresh()->place_name);
    }

    public function test_editing_a_turned_down_drive_sends_it_back_for_review(): void
    {
        $organiser = Devotee::factory()->create();
        $drive = $this->raise($organiser);
        $drive->forceFill(['status' => SevaDriveStatus::Rejected, 'moderation_note' => 'Add the address'])->save();

        $this->getJson("/api/v1/seva-drives/{$drive->id}")
            ->assertOk()
            ->assertJsonPath('data.mine.moderation_note', 'Add the address');

        $this->patchJson("/api/v1/me/seva-drives/{$drive->id}", ['address' => 'Temple street, Kolanupaka'])
            ->assertOk()
            ->assertJsonPath('data.status.value', 'pending');
    }

    public function test_only_the_organiser_can_change_a_drive(): void
    {
        $drive = $this->raise(Devotee::factory()->create());

        Sanctum::actingAs(Devotee::factory()->create(), guard: 'devotee');

        $this->patchJson("/api/v1/me/seva-drives/{$drive->id}", ['title' => 'Hijacked drive title'])->assertNotFound();
        $this->postJson("/api/v1/me/seva-drives/{$drive->id}/cancel")->assertNotFound();
        $this->getJson("/api/v1/me/seva-drives/{$drive->id}/volunteers")->assertNotFound();
    }

    public function test_donations_are_reported_and_count_once_the_organiser_confirms(): void
    {
        $organiser = Devotee::factory()->create();
        $drive = $this->raise($organiser);

        $donor = Devotee::factory()->create();
        Sanctum::actingAs($donor, guard: 'devotee');

        // Not verified: no donations.
        $this->postJson("/api/v1/seva-drives/{$drive->id}/donations", ['amount' => 500])->assertUnprocessable();

        $drive->forceFill(['status' => SevaDriveStatus::Completed, 'verified_at' => now()])->save();

        $donationId = $this->postJson("/api/v1/seva-drives/{$drive->id}/donations", [
            'amount' => 500,
            'upi_ref' => '425312345678',
            'payment_app' => 'phonepe',
            'paid_on' => now()->subDay()->toDateString(),
        ])->assertCreated()
            ->assertJsonPath('data.payment_app_label', 'PhonePe')
            ->assertJsonPath('data.paid_on', now()->subDay()->toDateString())
            ->json('data.id');

        $this->postJson("/api/v1/seva-drives/{$drive->id}/donations", ['amount' => 100, 'paid_on' => now()->addDay()->toDateString()])
            ->assertUnprocessable()->assertJsonValidationErrors('paid_on');
        $this->postJson("/api/v1/seva-drives/{$drive->id}/donations", ['amount' => 100, 'payment_app' => 'bitcoin'])
            ->assertUnprocessable()->assertJsonValidationErrors('payment_app');

        $this->getJson("/api/v1/seva-drives/{$drive->id}")->assertJsonPath('data.donations.raised', 0);

        Sanctum::actingAs($organiser, guard: 'devotee');
        $this->postJson("/api/v1/me/seva-drives/{$drive->id}/donations/{$donationId}/confirm")->assertOk();

        $this->getJson("/api/v1/seva-drives/{$drive->id}")->assertJsonPath('data.donations.raised', 500);
    }

    public function test_the_listing_shows_upcoming_drives_and_finished_ones_separately(): void
    {
        $upcoming = $this->raise(Devotee::factory()->create(), ['title' => 'Upcoming tank cleaning']);
        $upcoming->forceFill(['status' => SevaDriveStatus::Approved])->save();

        $done = $this->raise(Devotee::factory()->create(), ['title' => 'Finished whitewash drive']);
        $done->forceFill(['status' => SevaDriveStatus::Completed, 'verified_at' => now(), 'completed_at' => now()])->save();

        $this->getJson('/api/v1/seva-drives')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Upcoming tank cleaning');

        $this->getJson('/api/v1/seva-drives?when=done')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Finished whitewash drive');
    }

    public function test_a_drive_can_point_at_a_listed_temple(): void
    {
        $temple = Temple::create(['name' => 'Kolanupaka Jain Temple', 'status' => TempleStatus::Published]);

        $drive = $this->raise(Devotee::factory()->create(), ['temple' => $temple->slug]);

        $this->assertSame($temple->id, $drive->temple_id);
    }

    public function test_media_per_stage_is_capped(): void
    {
        $organiser = Devotee::factory()->create();
        Sanctum::actingAs($organiser, guard: 'devotee');

        $photos = array_map(fn (int $n) => UploadedFile::fake()->image("p{$n}.jpg"), range(1, SevaDriveMedia::MAX_PER_STAGE));

        $id = $this->post('/api/v1/me/seva-drives', [...$this->details(), 'photos' => $photos], self::JSON)
            ->assertCreated()
            ->json('data.id');

        $this->post("/api/v1/me/seva-drives/{$id}/media", [
            'stage' => 'before',
            'photos' => [UploadedFile::fake()->image('one-too-many.jpg')],
        ], self::JSON)->assertUnprocessable()->assertJsonValidationErrors('photos');
    }

    public function test_anyone_sees_how_many_are_coming_and_how_much_was_raised(): void
    {
        $drive = $this->raise(Devotee::factory()->create());
        $drive->forceFill(['status' => SevaDriveStatus::Completed, 'verified_at' => now()])->save();
        $drive->volunteers()->create(['devotee_id' => Devotee::factory()->create()->id, 'party_size' => 4]);
        $drive->donations()->create(['amount' => 300])->forceFill(['confirmed_at' => now()])->save();
        $drive->donations()->create(['amount' => 900]); // not confirmed: does not count

        auth('devotee')->forgetUser();
        $this->app['auth']->forgetGuards();

        $this->getJson("/api/v1/seva-drives/{$drive->id}")
            ->assertOk()
            ->assertJsonPath('data.volunteers_joined', 4)
            ->assertJsonPath('data.signups', 1)
            ->assertJsonPath('data.donations.raised', 300)
            ->assertJsonPath('data.donations.donors', 1)
            ->assertJsonPath('data.organiser.is_team', false)
            ->assertJsonPath('data.is_multi_day', false);
    }

    public function test_the_organiser_sees_who_is_coming(): void
    {
        $organiser = Devotee::factory()->create();
        $drive = $this->raise($organiser);
        $drive->forceFill(['status' => SevaDriveStatus::Approved])->save();
        $volunteer = Devotee::factory()->create(['name' => 'Lakshmi']);
        $drive->volunteers()->create(['devotee_id' => $volunteer->id, 'party_size' => 2, 'note' => 'Bringing sacks']);

        Sanctum::actingAs($organiser, guard: 'devotee');

        $this->getJson("/api/v1/me/seva-drives/{$drive->id}/volunteers")
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Lakshmi')
            ->assertJsonPath('data.0.party_size', 2)
            ->assertJsonPath('data.0.note', 'Bringing sacks');
    }

    public function test_a_blocked_drive_shows_the_organiser_why_and_cannot_be_edited(): void
    {
        $organiser = Devotee::factory()->create();
        $drive = $this->raise($organiser);
        $drive->block('Photographs are not of this place.');

        $this->getJson("/api/v1/seva-drives/{$drive->id}")
            ->assertOk()
            ->assertJsonPath('data.status.value', 'blocked')
            ->assertJsonPath('data.mine.block_reason', 'Photographs are not of this place.');

        $this->patchJson("/api/v1/me/seva-drives/{$drive->id}", ['title' => 'Trying again with a new title'])
            ->assertUnprocessable();
    }

    public function test_a_misleading_drive_cannot_be_joined(): void
    {
        $drive = $this->raise(Devotee::factory()->create());
        $drive->forceFill(['status' => SevaDriveStatus::Approved, 'is_misleading' => true, 'misleading_note' => 'Wrong place'])->save();

        Sanctum::actingAs(Devotee::factory()->create(), guard: 'devotee');

        $this->getJson("/api/v1/seva-drives/{$drive->id}")->assertJsonPath('data.viewer.can_join', false);
        $this->postJson("/api/v1/seva-drives/{$drive->id}/join")->assertUnprocessable();
    }

    public function test_the_finished_list_can_show_only_verified_drives(): void
    {
        $verified = $this->raise(Devotee::factory()->create(), ['title' => 'Verified tank clean-up']);
        $verified->forceFill(['status' => SevaDriveStatus::Completed, 'verified_at' => now(), 'completed_at' => now()])->save();
        $done = $this->raise(Devotee::factory()->create(), ['title' => 'Done, not yet verified']);
        $done->forceFill(['status' => SevaDriveStatus::Completed, 'completed_at' => now()])->save();

        $this->getJson('/api/v1/seva-drives?when=done')->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/seva-drives?when=done&verified=1')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Verified tank clean-up');
    }

    public function test_a_drive_keeps_its_pin_code_and_district(): void
    {
        $drive = $this->raise(Devotee::factory()->create(), ['pincode' => '508101', 'district' => 'Yadadri Bhuvanagiri']);

        $this->assertSame('508101', $drive->pincode);
        $this->getJson("/api/v1/seva-drives/{$drive->id}")
            ->assertJsonPath('data.place.pincode', '508101')
            ->assertJsonPath('data.place.district', 'Yadadri Bhuvanagiri');
    }

    public function test_drives_verified_under_the_old_rules_become_completed_and_keep_the_badge(): void
    {
        $drive = $this->raise(Devotee::factory()->create());
        \Illuminate\Support\Facades\DB::table('seva_drives')->where('id', $drive->id)->update(['status' => 'verified', 'verified_at' => null]);

        (require database_path('migrations/2026_10_02_000001_separate_seva_verification_from_status.php'))->up();

        $drive->refresh();
        $this->assertSame(SevaDriveStatus::Completed, $drive->status);
        $this->assertTrue($drive->isVerified());
    }

    public function test_a_donor_sees_their_donation_marked_paid_once_the_organiser_confirms(): void
    {
        $organiser = Devotee::factory()->create();
        $drive = $this->raise($organiser);
        $drive->verify(null);

        $donor = Devotee::factory()->create(['name' => 'Lakshmi']);
        Sanctum::actingAs($donor, guard: 'devotee');
        $id = $this->postJson("/api/v1/seva-drives/{$drive->id}/donations", ['amount' => 501, 'payment_app' => 'gpay', 'upi_ref' => '425399991111'])->json('data.id');

        $this->getJson("/api/v1/seva-drives/{$drive->id}")
            ->assertJsonPath('data.my_donations.0.amount', 501)
            ->assertJsonPath('data.my_donations.0.payment_app_label', 'Google Pay')
            ->assertJsonPath('data.my_donations.0.confirmed', false)
            ->assertJsonCount(0, 'data.supporters');

        Sanctum::actingAs($organiser, guard: 'devotee');
        $this->postJson("/api/v1/me/seva-drives/{$drive->id}/donations/{$id}/confirm")->assertOk();

        Sanctum::actingAs($donor, guard: 'devotee');
        $this->getJson("/api/v1/seva-drives/{$drive->id}")
            ->assertJsonPath('data.my_donations.0.confirmed', true)
            ->assertJsonPath('data.supporters.0.name', 'Lakshmi')
            ->assertJsonPath('data.supporters.0.amount', 501);

        // Somebody else sees the supporter, and none of the donor's own rows.
        Sanctum::actingAs(Devotee::factory()->create(), guard: 'devotee');
        $this->getJson("/api/v1/seva-drives/{$drive->id}")
            ->assertJsonCount(0, 'data.my_donations')
            ->assertJsonPath('data.supporters.0.name', 'Lakshmi');
    }
}
