<?php

namespace Tests\Feature\Filament;

use App\Enums\UserRole;
use App\Filament\Resources\Devotees\Pages\ListDevotees;
use App\Filament\Resources\Devotees\Pages\ViewDevotee;
use App\Models\Devotee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Support confirming a devotee's email or phone and editing their details
 * at their request; super admins only.
 */
class DevoteeVerifyAndEditTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::factory()->create(['role' => UserRole::SuperAdmin, 'is_active' => true]);
    }

    public function test_a_super_admin_marks_email_and_phone_verified_and_removes_it(): void
    {
        $devotee = Devotee::factory()->create(['email' => 'anu@example.org', 'phone' => '9876543210', 'email_verified_at' => null, 'phone_verified_at' => null]);
        $this->actingAs($this->admin());

        Livewire::test(ViewDevotee::class, ['record' => $devotee->getRouteKey()])
            ->assertActionVisible('verify_email')
            ->callAction('verify_email')
            ->callAction('verify_phone');

        $devotee->refresh();
        $this->assertNotNull($devotee->email_verified_at);
        $this->assertNotNull($devotee->phone_verified_at);
        $this->assertTrue($devotee->isVerified());

        Livewire::test(ListDevotees::class)->callTableAction('unverify', $devotee);
        $this->assertFalse($devotee->refresh()->isVerified());
    }

    public function test_editing_details_clears_the_verification_of_a_changed_contact(): void
    {
        $devotee = Devotee::factory()->create(['name' => 'Anu', 'email' => 'anu@example.org', 'phone' => '9876543210', 'email_verified_at' => now(), 'phone_verified_at' => now()]);
        Devotee::factory()->create(['email' => 'taken@example.org', 'phone' => null]);
        $this->actingAs($this->admin());

        Livewire::test(ViewDevotee::class, ['record' => $devotee->getRouteKey()])
            ->callAction('edit_details', data: ['name' => 'Anuradha', 'email' => 'taken@example.org', 'phone' => '9876543210'])
            ->assertHasActionErrors(['email']);

        Livewire::test(ViewDevotee::class, ['record' => $devotee->getRouteKey()])
            ->callAction('edit_details', data: ['name' => 'Anuradha', 'email' => 'Anuradha@Example.org', 'phone' => '9876543210', 'gender' => 'female'])
            ->assertHasNoActionErrors();

        $devotee->refresh();
        $this->assertSame('Anuradha', $devotee->name);
        $this->assertSame('anuradha@example.org', $devotee->email);
        $this->assertNull($devotee->email_verified_at);
        // The phone did not change, so it stays verified.
        $this->assertNotNull($devotee->phone_verified_at);
    }

    public function test_editors_cannot_edit_or_verify(): void
    {
        $devotee = Devotee::factory()->create(['email_verified_at' => null]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Editor, 'is_active' => true]));

        Livewire::test(ViewDevotee::class, ['record' => $devotee->getRouteKey()])
            ->assertActionHidden('edit_details')
            ->assertActionHidden('verify_email');
    }
}
