<?php

namespace Tests\Feature\Console;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_the_first_admin(): void
    {
        $this->artisan('admin:create', [
            'email' => 'first@example.com',
            '--password' => 'CorrectHorseBattery',
        ])->assertSuccessful();

        $user = User::where('email', 'first@example.com')->firstOrFail();

        $this->assertTrue(Hash::check('CorrectHorseBattery', $user->password));
        $this->assertSame(UserRole::SuperAdmin, $user->role);
        $this->assertTrue($user->is_active);
    }

    public function test_it_resets_the_password_of_an_existing_account(): void
    {
        $user = User::factory()->create([
            'email' => 'existing@example.com',
            'password' => 'TheOldPassword123',
        ]);

        $this->artisan('admin:create', [
            'email' => 'existing@example.com',
            '--password' => 'TheNewPassword123',
        ])->assertSuccessful();

        $user->refresh();

        // This is the recovery path for an imported dump, where the database
        // holds a hash whose plaintext the importer never saw.
        $this->assertTrue(Hash::check('TheNewPassword123', $user->password));
        $this->assertFalse(Hash::check('TheOldPassword123', $user->password));
        $this->assertSame(1, User::count(), 'It must reset, not create a duplicate.');
    }

    public function test_it_reactivates_a_deactivated_account(): void
    {
        User::factory()->create(['email' => 'locked@example.com', 'is_active' => false]);

        $this->artisan('admin:create', [
            'email' => 'locked@example.com',
            '--password' => 'BackInBusiness123',
        ])->assertSuccessful();

        // Resetting the password of an account that cannot sign in would be
        // a confusing no-op.
        $this->assertTrue(User::where('email', 'locked@example.com')->first()->is_active);
    }

    public function test_it_can_create_an_editor(): void
    {
        $this->artisan('admin:create', [
            'email' => 'editor@example.com',
            '--password' => 'EditorPassword123',
            '--role' => 'editor',
        ])->assertSuccessful();

        $user = User::where('email', 'editor@example.com')->firstOrFail();

        $this->assertSame(UserRole::Editor, $user->role);
        $this->assertFalse($user->canPublish());
    }

    public function test_it_rejects_an_unknown_role(): void
    {
        $this->artisan('admin:create', [
            'email' => 'nobody@example.com',
            '--password' => 'SomePassword1234',
            '--role' => 'wizard',
        ])->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_it_rejects_a_short_password(): void
    {
        $this->artisan('admin:create', [
            'email' => 'nobody@example.com',
            '--password' => 'short',
        ])->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_it_rejects_an_invalid_email(): void
    {
        $this->artisan('admin:create', [
            'email' => 'not-an-email',
            '--password' => 'LongEnoughPassword',
        ])->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_it_lists_existing_accounts_before_asking(): void
    {
        User::factory()->create(['email' => 'already@example.com', 'role' => UserRole::Editor]);

        // Typing the wrong address looks identical to typing the wrong
        // password, so the command shows what actually exists.
        $this->artisan('admin:create', [
            'email' => 'already@example.com',
            '--password' => 'AnotherPassword123',
        ])
            ->expectsOutputToContain('already@example.com')
            ->assertSuccessful();
    }
}
