<?php

namespace Tests\Feature\Filament;

use App\Enums\SevaDriveStatus;
use App\Enums\UserRole;
use App\Filament\Resources\SevaDrives\Pages\ListSevaDrives;
use App\Filament\Resources\SevaDrives\Pages\ViewSevaDrive;
use App\Filament\Resources\SevaDrives\SevaDriveResource;
use App\Models\Devotee;
use App\Models\SevaDrive;
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
}
