<?php

namespace Tests\Feature\Filament;

use App\Enums\UserRole;
use App\Filament\Pages\Auth\Profile;
use App\Models\Temple;
use App\Models\TempleUser;
use App\Models\User;
use Filament\Widgets\AccountWidget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The account page that replaced the dashboard's sign-out card.
 *
 * The AccountWidget took a full-width tile at the top of the dashboard to
 * offer a sign-out button the user menu already carries. What was missing was
 * the opposite: somewhere to change your own name, email and password, and to
 * see what your account actually covers.
 */
class ProfilePageTest extends TestCase
{
    use RefreshDatabase;

    protected function staff(): User
    {
        return User::factory()->create(['role' => UserRole::SuperAdmin, 'is_active' => true]);
    }

    protected function templeAdmin(): User
    {
        return User::factory()->create(['role' => UserRole::TempleAdmin, 'is_active' => true]);
    }

    public function test_the_dashboard_no_longer_carries_the_sign_out_card(): void
    {
        $widgets = filament()->getPanel('admin')->getWidgets();

        $this->assertNotContains(AccountWidget::class, $widgets);

        // Not merely unregistered: discovery must not drag it back in either.
        foreach ($widgets as $widget) {
            $this->assertFalse(
                is_a($widget, AccountWidget::class, allow_string: true),
                'The account widget is still on the dashboard.',
            );
        }
    }

    public function test_both_panels_register_the_profile_page(): void
    {
        foreach (['admin', 'temple'] as $panel) {
            $this->assertSame(
                Profile::class,
                filament()->getPanel($panel)->getProfilePage(),
                "The {$panel} panel has no profile page.",
            );
        }
    }

    public function test_a_staff_member_can_open_their_profile(): void
    {
        $user = $this->staff();

        $this->actingAs($user)
            ->get(Profile::getUrl(panel: 'admin'))
            ->assertOk()
            ->assertSee('My profile')
            ->assertSee($user->email)
            ->assertSee('Super Admin')
            ->assertSee('Admin Panel');
    }

    public function test_a_temple_admin_sees_the_temples_their_account_covers(): void
    {
        $user = $this->templeAdmin();
        $temple = Temple::create(['name' => 'Kashi Vishwanath']);

        TempleUser::create([
            'temple_id' => $temple->id,
            'user_id' => $user->id,
            'requested_at' => now(),
            'approved_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(Profile::getUrl(panel: 'temple'))
            ->assertOk()
            ->assertSee('Temples you maintain')
            ->assertSee('Kashi Vishwanath')
            ->assertSee('Temple Portal');
    }

    /** An unapproved claim grants nothing, and the page must not imply it does. */
    public function test_a_temple_admin_with_no_approved_claim_is_told_so(): void
    {
        $user = $this->templeAdmin();
        $temple = Temple::create(['name' => 'Unapproved Temple']);

        TempleUser::create([
            'temple_id' => $temple->id,
            'user_id' => $user->id,
            'requested_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(Profile::getUrl(panel: 'temple'))
            ->assertOk()
            ->assertSee('approve your claim', escape: false)
            ->assertDontSee('Unapproved Temple');
    }

    public function test_staff_do_not_see_a_temples_section(): void
    {
        $this->actingAs($this->staff())
            ->get(Profile::getUrl(panel: 'admin'))
            ->assertOk()
            ->assertDontSee('Temples you maintain');
    }

    public function test_a_name_change_is_saved(): void
    {
        $user = $this->staff();

        Livewire::actingAs($user)
            ->test(Profile::class)
            ->fillForm(['name' => 'Renamed Admin'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Renamed Admin', $user->fresh()->name);
    }

    /** Changing the password without proving the current one must not work. */
    public function test_a_password_change_requires_the_current_password(): void
    {
        $user = $this->staff();
        $original = $user->password;

        Livewire::actingAs($user)
            ->test(Profile::class)
            ->fillForm([
                'password' => 'a-brand-new-passphrase',
                'passwordConfirmation' => 'a-brand-new-passphrase',
                'currentPassword' => 'not-the-current-one',
            ])
            ->call('save')
            ->assertHasFormErrors(['currentPassword']);

        $this->assertSame($original, $user->fresh()->password);
    }

    public function test_a_password_change_works_with_the_current_password(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::SuperAdmin,
            'is_active' => true,
            'password' => Hash::make('the-original-passphrase'),
        ]);

        Livewire::actingAs($user)
            ->test(Profile::class)
            ->fillForm([
                'password' => 'a-brand-new-passphrase',
                'passwordConfirmation' => 'a-brand-new-passphrase',
                'currentPassword' => 'the-original-passphrase',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue(Hash::check('a-brand-new-passphrase', $user->fresh()->password));
    }

    /** Nothing on an admin page may depend on a third party being reachable. */
    public function test_no_panel_page_fetches_an_avatar_from_another_host(): void
    {
        $this->actingAs($this->staff())
            ->get(Profile::getUrl(panel: 'admin'))
            ->assertOk()
            ->assertDontSee('ui-avatars.com');

        foreach (['admin', 'temple'] as $panel) {
            $this->assertSame(
                \App\Support\InitialsAvatarProvider::class,
                filament()->getPanel($panel)->getDefaultAvatarProvider(),
            );
        }
    }

    public function test_a_guest_cannot_reach_the_profile_page(): void
    {
        $this->get(Profile::getUrl(panel: 'admin'))->assertRedirect('/admin/login');
    }
}
