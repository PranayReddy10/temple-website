<?php

namespace Tests\Feature\Filament;

use App\Enums\SevaDriveStatus;
use App\Enums\UserRole;
use App\Filament\Resources\SevaDrives\Pages\CreateSevaDrive;
use App\Filament\Resources\SevaDrives\Pages\EditSevaDrive;
use App\Filament\Resources\SevaDrives\Pages\ListSevaDrives;
use App\Filament\Resources\SevaDrives\Pages\ViewSevaDrive;
use App\Filament\Resources\SevaDrives\SevaDriveResource;
use App\Models\Devotee;
use App\Models\SevaDrive;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SevaDriveAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function staff(UserRole $role = UserRole::SuperAdmin): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    protected function drive(array $attributes = []): SevaDrive
    {
        $drive = new SevaDrive([
            'title' => 'Clean the stepwell',
            'cause' => 'water_body',
            'place_name' => 'Old stepwell',
            'problem' => 'Silt and plastic have filled the lower steps.',
            'plan' => 'Remove the plastic by hand and carry the silt out.',
            'starts_at' => now()->addDays(3),
            'upi_id' => 'stepwell@okicici',
        ]);
        $drive->devotee_id = Devotee::factory()->create()->id;
        $drive->forceFill($attributes)->save();

        return $drive;
    }

    public function test_staff_can_reach_the_queue_and_a_drive(): void
    {
        $this->actingAs($this->staff(UserRole::Editor));
        $drive = $this->drive();

        $this->get(SevaDriveResource::getUrl('index'))->assertOk();
        $this->get(SevaDriveResource::getUrl('view', ['record' => $drive]))->assertOk()->assertSee('Clean the stepwell');
    }

    public function test_a_temple_admin_cannot(): void
    {
        $this->actingAs($this->staff(UserRole::TempleAdmin))
            ->get(SevaDriveResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_approving_opens_it_to_volunteers(): void
    {
        $staff = $this->staff();
        $this->actingAs($staff);
        $drive = $this->drive();

        Livewire::test(ViewSevaDrive::class, ['record' => $drive->getRouteKey()])
            ->callAction('approve');

        $drive->refresh();
        $this->assertSame(SevaDriveStatus::Approved, $drive->status);
        $this->assertSame($staff->id, $drive->moderated_by);
    }

    public function test_turning_down_records_the_reason(): void
    {
        $this->actingAs($this->staff());
        $drive = $this->drive();

        Livewire::test(ListSevaDrives::class)
            ->set('activeTab', 'review')
            ->callTableAction('reject', $drive, data: ['moderation_note' => 'Please add the address.']);

        $drive->refresh();
        $this->assertSame(SevaDriveStatus::Rejected, $drive->status);
        $this->assertSame('Please add the address.', $drive->moderation_note);
    }

    public function test_verifying_the_work_opens_donations(): void
    {
        $staff = $this->staff();
        $this->actingAs($staff);
        $drive = $this->drive(['status' => SevaDriveStatus::Completed, 'completed_at' => now()]);

        $this->assertFalse($drive->acceptsDonations());

        Livewire::test(ViewSevaDrive::class, ['record' => $drive->getRouteKey()])
            ->callAction('verify');

        $drive->refresh();
        $this->assertSame(SevaDriveStatus::Verified, $drive->status);
        $this->assertSame($staff->id, $drive->verified_by);
        $this->assertTrue($drive->acceptsDonations());
        $this->assertStringStartsWith('upi://pay?pa=stepwell@okicici', $drive->upiLink());
    }

    public function test_pausing_donations_hides_the_upi_id(): void
    {
        $this->actingAs($this->staff());
        $drive = $this->drive(['status' => SevaDriveStatus::Verified]);

        Livewire::test(ViewSevaDrive::class, ['record' => $drive->getRouteKey()])
            ->callAction('toggle_donations');

        $this->assertFalse($drive->refresh()->acceptsDonations());
    }

    public function test_staff_create_a_team_drive_with_no_devotee_behind_it(): void
    {
        $staff = $this->staff();
        $this->actingAs($staff);

        Livewire::test(CreateSevaDrive::class)
            ->fillForm([
                'organiser_name' => 'Kolanupaka youth volunteers',
                'title' => 'Whitewash the old mandapam',
                'cause' => 'painting',
                'status' => SevaDriveStatus::Approved->value,
                'place_name' => 'Someshwara mandapam',
                'problem' => 'The walls are black with soot and moss.',
                'plan' => 'Scrub the walls and apply two coats of lime.',
                'starts_at' => now()->addDays(5)->setTime(7, 0),
                'ends_at' => now()->addDays(6)->setTime(17, 0),
                'donations_enabled' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $drive = SevaDrive::query()->latest('id')->firstOrFail();
        $this->assertNull($drive->devotee_id);
        $this->assertSame($staff->id, $drive->created_by);
        $this->assertSame(SevaDriveStatus::Approved, $drive->status);
        $this->assertSame('Kolanupaka youth volunteers', $drive->organiserName());
        $this->assertTrue($drive->isMultiDay());
        $this->assertSame(2, $drive->dayCount());

        $this->getJson("/api/v1/seva-drives/{$drive->id}")
            ->assertOk()
            ->assertJsonPath('data.organiser.name', 'Kolanupaka youth volunteers')
            ->assertJsonPath('data.organiser.is_team', true)
            ->assertJsonPath('data.is_multi_day', true);
    }

    public function test_staff_edit_what_a_devotee_raised(): void
    {
        $this->actingAs($this->staff());
        $drive = $this->drive();

        Livewire::test(EditSevaDrive::class, ['record' => $drive->getRouteKey()])
            ->fillForm(['title' => 'Clean the old stepwell at Hampi', 'donations_enabled' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $drive->refresh();
        $this->assertSame('Clean the old stepwell at Hampi', $drive->title);
        $this->assertFalse($drive->donations_enabled);
    }

    public function test_blocking_hides_it_and_unblocking_puts_it_back(): void
    {
        $this->actingAs($this->staff());
        $drive = $this->drive(['status' => SevaDriveStatus::Approved]);

        Livewire::test(ViewSevaDrive::class, ['record' => $drive->getRouteKey()])
            ->callAction('block', data: ['block_reason' => 'Not a real place.']);

        $drive->refresh();
        $this->assertSame(SevaDriveStatus::Blocked, $drive->status);
        $this->getJson("/api/v1/seva-drives/{$drive->id}")->assertNotFound();
        $this->getJson('/api/v1/seva-drives')->assertJsonCount(0, 'data');

        Livewire::test(ViewSevaDrive::class, ['record' => $drive->getRouteKey()])
            ->callAction('unblock');

        $this->assertSame(SevaDriveStatus::Approved, $drive->refresh()->status);
    }

    public function test_marking_misleading_warns_everybody_and_closes_joining_and_donations(): void
    {
        $this->actingAs($this->staff());
        $drive = $this->drive(['status' => SevaDriveStatus::Verified]);
        $this->assertTrue($drive->acceptsDonations());

        Livewire::test(ViewSevaDrive::class, ['record' => $drive->getRouteKey()])
            ->callAction('mark_misleading', data: ['misleading_note' => 'The after photographs are of a different tank.']);

        $drive->refresh();
        $this->assertFalse($drive->acceptsDonations());

        $this->getJson("/api/v1/seva-drives/{$drive->id}")
            ->assertOk()
            ->assertJsonPath('data.is_misleading', true)
            ->assertJsonPath('data.misleading_note', 'The after photographs are of a different tank.')
            ->assertJsonPath('data.donations.upi_id', null);
    }

    public function test_deleting_removes_the_drive(): void
    {
        $this->actingAs($this->staff());
        $drive = $this->drive();

        Livewire::test(ViewSevaDrive::class, ['record' => $drive->getRouteKey()])
            ->callAction('delete');

        $this->assertModelMissing($drive);
    }

    public function test_reports_from_the_app_reach_the_drive(): void
    {
        $this->actingAs($this->staff());
        $drive = $this->drive(['status' => SevaDriveStatus::Approved]);

        $this->postJson('/api/v1/support', [
            'subject' => 'Report: Clean the stepwell',
            'body' => 'This drive is asking for money for a place that was cleaned years ago.',
            'category' => 'wrong_information',
            'name' => 'A devotee',
            'about_type' => 'seva_drive',
            'about_id' => $drive->id,
        ])->assertCreated();

        $this->assertSame(1, $drive->reports()->open()->count());
        $this->assertInstanceOf(SevaDrive::class, SupportTicket::query()->firstOrFail()->about);

        $this->get(SevaDriveResource::getUrl('view', ['record' => $drive]))->assertOk();
    }

    public function test_a_drive_nobody_can_see_cannot_be_reported(): void
    {
        $drive = $this->drive();

        $this->postJson('/api/v1/support', [
            'subject' => 'Report', 'body' => 'x', 'name' => 'A', 'about_type' => 'seva_drive', 'about_id' => $drive->id,
        ])->assertNotFound();
    }
}
