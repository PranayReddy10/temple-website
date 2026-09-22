<?php

namespace Tests\Feature\Temple;

use App\Enums\TempleStatus;
use App\Enums\UserRole;
use App\Filament\Temple\Resources\MyTemples\MyTempleResource;
use App\Models\Temple;
use App\Models\TempleUser;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The security boundary of the temple portal.
 *
 * A temple admin must not be able to read or write any temple other than the
 * ones their claim has been approved for. These tests are the proof of that,
 * and they exercise the URL directly rather than the navigation, because
 * hiding a link is not access control.
 */
class TemplePortalAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function templeAdmin(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'role' => UserRole::TempleAdmin,
            'is_active' => true,
        ], $attributes));
    }

    protected function approveClaim(User $user, Temple $temple): TempleUser
    {
        return TempleUser::create([
            'temple_id' => $temple->id,
            'user_id' => $user->id,
            'requested_at' => now(),
            'approved_at' => now(),
        ]);
    }

    // --- Panel separation ---

    public function test_a_temple_admin_cannot_reach_the_editorial_panel(): void
    {
        $this->actingAs($this->templeAdmin())
            ->get('/admin')
            ->assertForbidden();
    }

    public function test_staff_cannot_reach_the_temple_portal(): void
    {
        foreach ([UserRole::SuperAdmin, UserRole::Editor] as $role) {
            $staff = User::factory()->create(['role' => $role, 'is_active' => true]);

            $this->actingAs($staff)
                ->get('/temple')
                ->assertForbidden();
        }
    }

    public function test_a_guest_is_sent_to_the_temple_login(): void
    {
        $this->get('/temple')->assertRedirect('/temple/login');
    }

    public function test_a_deactivated_temple_admin_is_locked_out(): void
    {
        $user = $this->templeAdmin(['is_active' => false]);
        $temple = Temple::create(['name' => 'Some Temple']);
        $this->approveClaim($user, $temple);

        $this->actingAs($user)->get('/temple')->assertForbidden();
    }

    // --- Scoping ---

    public function test_the_list_shows_only_approved_temples(): void
    {
        $user = $this->templeAdmin();
        $mine = Temple::create(['name' => 'My Temple']);
        Temple::create(['name' => 'Someone Elses Temple']);
        $this->approveClaim($user, $mine);

        $this->actingAs($user);

        $ids = MyTempleResource::getEloquentQuery()->pluck('id')->all();

        $this->assertSame([$mine->id], $ids);
    }

    public function test_an_unapproved_claim_grants_nothing(): void
    {
        $user = $this->templeAdmin();
        $temple = Temple::create(['name' => 'Pending Temple']);

        TempleUser::create([
            'temple_id' => $temple->id,
            'user_id' => $user->id,
            'requested_at' => now(),
        ]);

        $this->actingAs($user);

        // Anyone can claim to run a temple. Only approval grants access.
        $this->assertSame([], $user->approvedTempleIds());
        $this->assertSame([], MyTempleResource::getEloquentQuery()->pluck('id')->all());
    }

    public function test_a_rejected_claim_grants_nothing(): void
    {
        $user = $this->templeAdmin();
        $temple = Temple::create(['name' => 'Rejected Temple']);

        TempleUser::create([
            'temple_id' => $temple->id,
            'user_id' => $user->id,
            'requested_at' => now(),
            'rejection_reason' => 'Could not confirm the claimant represents this temple.',
        ]);

        $this->actingAs($user);

        $this->assertSame([], $user->approvedTempleIds());
    }

    public function test_another_temple_cannot_be_opened_by_url(): void
    {
        $user = $this->templeAdmin();
        $mine = Temple::create(['name' => 'My Temple']);
        $theirs = Temple::create(['name' => 'Their Temple']);
        $this->approveClaim($user, $mine);

        $this->actingAs($user);

        // Covered by both the list scope and the record-binding scope; this
        // asserts the outcome rather than which of the two delivered it.
        $this->get("/temple/temples/{$mine->id}/edit")->assertOk();
        $this->get("/temple/temples/{$theirs->id}/edit")->assertNotFound();
    }

    public function test_record_binding_is_scoped_independently_of_the_list(): void
    {
        $user = $this->templeAdmin();
        $theirs = Temple::create(['name' => 'Their Temple']);

        $this->actingAs($user);

        $found = MyTempleResource::getRecordRouteBindingEloquentQuery()
            ->whereKey($theirs->id)
            ->exists();

        $this->assertFalse($found);
    }

    public function test_approval_can_be_revoked(): void
    {
        $user = $this->templeAdmin();
        $temple = Temple::create(['name' => 'Revoked Temple']);
        $claim = $this->approveClaim($user, $temple);

        $this->actingAs($user);
        $this->assertSame([$temple->id], $user->approvedTempleIds());

        $claim->update(['approved_at' => null]);

        $this->get("/temple/temples/{$temple->id}/edit")->assertNotFound();
    }

    // --- What a temple admin may do ---

    public function test_a_temple_admin_cannot_create_or_delete_temples(): void
    {
        $user = $this->templeAdmin();
        $temple = Temple::create(['name' => 'My Temple']);
        $this->approveClaim($user, $temple);

        $this->actingAs($user);

        $this->assertFalse(MyTempleResource::canCreate());
        $this->assertFalse(MyTempleResource::canDelete($temple));
        $this->get('/temple/temples/create')->assertNotFound();
    }

    public function test_a_temple_admin_cannot_publish(): void
    {
        $user = $this->templeAdmin();
        $temple = Temple::create(['name' => 'Unreviewed Temple']);
        $this->approveClaim($user, $temple);

        $this->actingAs($user);

        // Publishing stays with editorial staff whatever panel you are in.
        $this->expectException(AuthorizationException::class);

        $temple->update(['status' => TempleStatus::Published]);
    }

    public function test_a_temple_admin_can_edit_their_own_temple(): void
    {
        $user = $this->templeAdmin();
        $temple = Temple::create(['name' => 'My Temple']);
        $this->approveClaim($user, $temple);

        $this->actingAs($user);

        $temple->update(['contact_phone' => '+91 40 1234 5678']);

        $this->assertSame('+91 40 1234 5678', $temple->fresh()->contact_phone);
    }

    public function test_the_portal_pages_render_for_an_approved_admin(): void
    {
        $user = $this->templeAdmin();
        $temple = Temple::create(['name' => 'Renderable Temple']);
        $this->approveClaim($user, $temple);

        $this->actingAs($user);

        $this->get('/temple')->assertOk();
        $this->get('/temple/temples')->assertOk();
        $this->get("/temple/temples/{$temple->id}/edit")->assertOk();
    }

    public function test_an_approved_admin_with_no_temples_sees_an_empty_list(): void
    {
        $this->actingAs($this->templeAdmin());

        // No claim at all must be an empty list, never an unscoped one.
        $this->get('/temple/temples')->assertOk();
        $this->assertSame([], MyTempleResource::getEloquentQuery()->pluck('id')->all());
    }
}
