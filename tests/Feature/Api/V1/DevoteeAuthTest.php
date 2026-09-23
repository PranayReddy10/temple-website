<?php

namespace Tests\Feature\Api\V1;

use App\Enums\TempleStatus;
use App\Enums\UserRole;
use App\Models\Devotee;
use App\Models\Temple;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DevoteeAuthTest extends TestCase
{
    use RefreshDatabase;

    // --- Registration ---

    public function test_a_devotee_can_register_with_an_email(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'A Devotee',
            'email' => 'devotee@example.com',
            'password' => 'a-good-password',
            'password_confirmation' => 'a-good-password',
        ])->assertCreated();

        $this->assertNotEmpty($response->json('data.token'));
        $this->assertSame('A Devotee', $response->json('data.devotee.name'));
        $this->assertDatabaseHas('devotees', ['email' => 'devotee@example.com']);
    }

    public function test_a_devotee_can_register_with_a_phone_number(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Phone Devotee',
            'phone' => '+919876543210',
            'password' => 'a-good-password',
            'password_confirmation' => 'a-good-password',
        ])->assertCreated();

        $this->assertDatabaseHas('devotees', ['phone' => '+919876543210']);
    }

    public function test_registration_needs_at_least_one_identifier(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Nobody',
            'password' => 'a-good-password',
            'password_confirmation' => 'a-good-password',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_the_password_must_be_confirmed(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'A Devotee',
            'email' => 'x@example.com',
            'password' => 'a-good-password',
            'password_confirmation' => 'something-else',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_the_response_never_contains_the_password_hash(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'A Devotee',
            'email' => 'safe@example.com',
            'password' => 'a-good-password',
            'password_confirmation' => 'a-good-password',
        ])->assertCreated();

        $this->assertArrayNotHasKey('password', $response->json('data.devotee'));
    }

    // --- Login ---

    public function test_a_devotee_can_sign_in_with_either_identifier(): void
    {
        Devotee::factory()->create([
            'email' => 'known@example.com',
            'phone' => '+911111111111',
            'password' => 'known-password',
        ]);

        foreach (['known@example.com', '+911111111111'] as $identifier) {
            $this->postJson('/api/v1/auth/login', [
                'identifier' => $identifier,
                'password' => 'known-password',
            ])->assertOk()->assertJsonStructure(['data' => ['token', 'devotee']]);
        }
    }

    public function test_a_wrong_password_and_an_unknown_account_look_identical(): void
    {
        Devotee::factory()->create(['email' => 'known@example.com', 'password' => 'known-password']);

        $wrongPassword = $this->postJson('/api/v1/auth/login', [
            'identifier' => 'known@example.com',
            'password' => 'wrong',
        ])->assertStatus(422);

        $unknownAccount = $this->postJson('/api/v1/auth/login', [
            'identifier' => 'nobody@example.com',
            'password' => 'wrong',
        ])->assertStatus(422);

        // Otherwise the endpoint becomes a way to discover which numbers and
        // addresses are registered.
        $this->assertSame(
            $wrongPassword->json('errors.identifier'),
            $unknownAccount->json('errors.identifier'),
        );
    }

    public function test_a_deactivated_devotee_cannot_sign_in(): void
    {
        Devotee::factory()->inactive()->create([
            'email' => 'gone@example.com',
            'password' => 'known-password',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'identifier' => 'gone@example.com',
            'password' => 'known-password',
        ])->assertStatus(422);
    }

    public function test_logout_revokes_only_the_current_token(): void
    {
        $devotee = Devotee::factory()->create(['password' => 'known-password']);
        $keep = $devotee->createToken('other device')->plainTextToken;
        $drop = $devotee->createToken('this device')->plainTextToken;

        $this->withToken($drop)->postJson('/api/v1/auth/logout')->assertOk();

        $this->assertSame(1, $devotee->fresh()->tokens()->count());
        $this->assertFalse($devotee->fresh()->tokens()->where('name', 'this device')->exists());

        // The guard caches its resolved user for the life of the process, and
        // a test process spans several requests where real HTTP would not.
        // Forgetting the guards is what makes the next call a fresh request.
        $this->app['auth']->forgetGuards();
        $this->withToken($drop)->getJson('/api/v1/me')->assertUnauthorized();

        // Signing out of a phone must not sign the devotee out of their tablet.
        $this->app['auth']->forgetGuards();
        $this->withToken($keep)->getJson('/api/v1/me')->assertOk();
    }

    // --- Separation from staff ---

    public function test_a_devotee_token_cannot_reach_the_admin_panels(): void
    {
        $devotee = Devotee::factory()->create();
        Sanctum::actingAs($devotee, [], 'devotee');

        // The panels authenticate the staff guard against the users table,
        // which has never heard of this model.
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get('/temple')->assertRedirect('/temple/login');
    }

    public function test_a_staff_user_cannot_use_the_devotee_endpoints(): void
    {
        $staff = User::factory()->create(['role' => UserRole::SuperAdmin]);

        $this->actingAs($staff)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_devotees_and_staff_live_in_different_tables(): void
    {
        Devotee::factory()->create(['email' => 'shared@example.com']);
        User::factory()->create(['email' => 'shared@example.com']);

        // The same address in both is fine, because they are different people
        // in different systems. One table would have made this a collision.
        $this->assertDatabaseCount('devotees', 1);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_a_devotee_has_no_role_column_to_escalate(): void
    {
        $devotee = Devotee::factory()->create();

        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('devotees', 'role'));
        $this->assertNull($devotee->getAttribute('role'));
    }

    // --- Profile ---

    public function test_the_profile_requires_a_token(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_a_devotee_can_read_and_update_their_profile(): void
    {
        $devotee = Devotee::factory()->create(['name' => 'Old Name']);
        Sanctum::actingAs($devotee, [], 'devotee');

        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.name', 'Old Name');

        $this->patchJson('/api/v1/me', ['name' => 'New Name', 'locale' => 'te'])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.locale', 'te');
    }

    public function test_a_devotee_can_give_their_gender_or_leave_it_out(): void
    {
        $devotee = Devotee::factory()->create();
        Sanctum::actingAs($devotee, [], 'devotee');

        $this->patchJson('/api/v1/me', ['gender' => 'female'])
            ->assertOk()
            ->assertJsonPath('data.gender', 'female')
            ->assertJsonPath('data.gender_label', 'Female');

        $this->patchJson('/api/v1/me', ['gender' => 'unicorn'])->assertStatus(422)->assertJsonValidationErrors('gender');

        $this->patchJson('/api/v1/me', ['gender' => null])->assertOk()->assertJsonPath('data.gender', null);
    }

    public function test_changing_an_identifier_clears_its_verification(): void
    {
        $devotee = Devotee::factory()->create([
            'email' => 'old@example.com',
            'email_verified_at' => now(),
        ]);
        Sanctum::actingAs($devotee, [], 'devotee');

        $this->patchJson('/api/v1/me', ['email' => 'new@example.com'])->assertOk();

        // A new address has not been confirmed just because the old one was.
        $this->assertNull($devotee->fresh()->email_verified_at);
    }

    public function test_an_identifier_cannot_be_taken_from_another_devotee(): void
    {
        Devotee::factory()->create(['email' => 'taken@example.com']);
        $devotee = Devotee::factory()->create(['email' => 'mine@example.com']);
        Sanctum::actingAs($devotee, [], 'devotee');

        $this->patchJson('/api/v1/me', ['email' => 'taken@example.com'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    // --- Saved temples ---

    public function test_a_devotee_can_save_and_remove_a_temple(): void
    {
        $devotee = Devotee::factory()->create();
        $temple = Temple::create(['name' => 'Saved Temple', 'status' => TempleStatus::Published]);
        Sanctum::actingAs($devotee, [], 'devotee');

        $this->putJson("/api/v1/me/saved-temples/{$temple->slug}", ['note' => 'Visit in December'])
            ->assertCreated();

        $this->getJson('/api/v1/me/saved-temples')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Saved Temple');

        $this->deleteJson("/api/v1/me/saved-temples/{$temple->slug}")->assertOk();

        $this->getJson('/api/v1/me/saved-temples')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_saving_the_same_temple_twice_is_harmless(): void
    {
        $devotee = Devotee::factory()->create();
        $temple = Temple::create(['name' => 'Saved Temple', 'status' => TempleStatus::Published]);
        Sanctum::actingAs($devotee, [], 'devotee');

        $this->putJson("/api/v1/me/saved-temples/{$temple->slug}")->assertCreated();
        $this->putJson("/api/v1/me/saved-temples/{$temple->slug}")->assertCreated();

        $this->assertDatabaseCount('devotee_saved_temples', 1);
    }

    public function test_an_unpublished_temple_cannot_be_saved(): void
    {
        $devotee = Devotee::factory()->create();
        $draft = Temple::create(['name' => 'Draft Temple', 'status' => TempleStatus::Draft]);
        Sanctum::actingAs($devotee, [], 'devotee');

        $this->putJson("/api/v1/me/saved-temples/{$draft->slug}")->assertNotFound();
    }
}
