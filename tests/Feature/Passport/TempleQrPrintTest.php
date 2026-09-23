<?php

namespace Tests\Feature\Passport;

use App\Enums\UserRole;
use App\Filament\Temple\Resources\MyTemples\Pages\EditMyTemple;
use App\Models\Temple;
use App\Models\TempleUser;
use App\Models\User;
use App\Support\TempleQr;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** A temple's team prints its own check-in code, and only its own. */
class TempleQrPrintTest extends TestCase
{
    use RefreshDatabase;

    protected function templeAdminOf(Temple $temple): User
    {
        $user = User::factory()->create(['role' => UserRole::TempleAdmin, 'is_active' => true]);
        TempleUser::create(['temple_id' => $temple->id, 'user_id' => $user->id, 'requested_at' => now(), 'approved_at' => now()]);

        return $user;
    }

    public function test_a_temple_admin_prints_their_own_temples_poster(): void
    {
        $temple = Temple::create(['name' => 'Kanaka Durga Temple']);

        $this->actingAs($this->templeAdminOf($temple))
            ->get(route('temples.qr.print', $temple))
            ->assertOk()
            ->assertSee('Kanaka Durga Temple')
            ->assertSee(TempleQr::url($temple), escape: true)
            ->assertSee('<svg', escape: false);

        $this->get(route('temples.qr.download', $temple))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/svg+xml');
    }

    public function test_another_temples_poster_is_not_found(): void
    {
        $mine = Temple::create(['name' => 'Mine']);
        $theirs = Temple::create(['name' => 'Theirs']);

        $this->actingAs($this->templeAdminOf($mine))
            ->get(route('temples.qr.print', $theirs))
            ->assertNotFound();
        $this->get(route('temples.qr.download', $theirs))->assertNotFound();
    }

    public function test_staff_print_any_temple_and_guests_are_sent_to_sign_in(): void
    {
        $temple = Temple::create(['name' => 'Any Temple']);

        $this->get(route('temples.qr.print', $temple))->assertRedirect('/temple/login');

        $this->actingAs(User::factory()->create(['role' => UserRole::Editor, 'is_active' => true]))
            ->get(route('temples.qr.print', $temple))
            ->assertOk();
    }

    public function test_the_portal_edit_page_offers_the_code(): void
    {
        $temple = Temple::create(['name' => 'Portal Temple']);
        $this->actingAs($this->templeAdminOf($temple));

        Livewire::test(EditMyTemple::class, ['record' => $temple->getRouteKey()])
            ->assertActionExists('checkinQr')
            ->assertActionExists('printCheckinQr')
            ->assertActionExists('scanPassport');
    }
}
