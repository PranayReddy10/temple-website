<?php

namespace Tests\Feature\Filament;

use App\Enums\UserRole;
use App\Filament\Resources\TempleAccess\Pages\ListTempleAccess;
use App\Filament\Resources\TempleAccess\Schemas\TempleAccessForm;
use App\Filament\Resources\TempleAccess\TempleAccessResource;
use App\Filament\Resources\Temples\Pages\EditTemple;
use App\Filament\Resources\Temples\RelationManagers\ClaimsRelationManager;
use App\Models\Temple;
use App\Models\TempleUser;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Granting a temple's own trust access to their listing.
 *
 * This is the one workflow that was unreachable in practice: the account
 * dropdown could only ever list Temple Admin accounts, and on a fresh
 * install there are none, so the screen offered a required field with
 * nothing in it and no way forward. The account can now be created from the
 * same form, and the same grant is reachable from the side menu as well as
 * from inside a temple.
 */
class TempleAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function superAdmin(): User
    {
        return User::factory()->create(['role' => UserRole::SuperAdmin, 'is_active' => true]);
    }

    protected function editor(): User
    {
        return User::factory()->create(['role' => UserRole::Editor, 'is_active' => true]);
    }

    protected function templeAdmin(): User
    {
        return User::factory()->create(['role' => UserRole::TempleAdmin, 'is_active' => true]);
    }

    protected function temple(string $name = 'Access Temple'): Temple
    {
        return Temple::create(['name' => $name]);
    }

    /**
     * The regression that made the nested screen unusable as soon as it had
     * a row: TempleUser is a Pivot, and a pivot used as a model has no
     * relations unless it declares them. An empty table hid it, because
     * Laravel skips eager loading when there is nothing to load.
     */
    public function test_a_claim_resolves_its_temple_account_and_approver(): void
    {
        $approver = $this->superAdmin();
        $account = $this->templeAdmin();
        $temple = $this->temple();

        $claim = TempleUser::create([
            'temple_id' => $temple->id,
            'user_id' => $account->id,
            'role' => 'owner',
            'requested_at' => now(),
            'approved_at' => now(),
            'approved_by' => $approver->id,
        ]);

        $loaded = TempleUser::with(['temple', 'user', 'approver'])->findOrFail($claim->getKey());

        $this->assertTrue($loaded->temple->is($temple));
        $this->assertTrue($loaded->user->is($account));
        $this->assertTrue($loaded->approver->is($approver));
    }

    public function test_the_nested_manager_renders_an_existing_claim(): void
    {
        $this->actingAs($this->superAdmin());
        $temple = $this->temple();

        TempleUser::create([
            'temple_id' => $temple->id,
            'user_id' => $this->templeAdmin()->id,
            'role' => 'manager',
            'requested_at' => now(),
        ]);

        Livewire::test(ClaimsRelationManager::class, [
            'ownerRecord' => $temple,
            'pageClass' => EditTemple::class,
        ])
            ->assertOk()
            ->assertCanSeeTableRecords(TempleUser::all());
    }

    public function test_the_side_menu_list_shows_claims_from_every_temple(): void
    {
        $this->actingAs($this->superAdmin());

        $first = TempleUser::create([
            'temple_id' => $this->temple('Kashi Vishwanath')->id,
            'user_id' => $this->templeAdmin()->id,
            'requested_at' => now(),
        ]);

        $second = TempleUser::create([
            'temple_id' => $this->temple('Rameswaram')->id,
            'user_id' => $this->templeAdmin()->id,
            'requested_at' => now(),
        ]);

        Livewire::test(ListTempleAccess::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$first, $second])
            ->assertSee('Kashi Vishwanath')
            ->assertSee('Rameswaram');
    }

    public function test_the_side_menu_list_is_reachable_by_url(): void
    {
        $this->actingAs($this->superAdmin())
            ->get(TempleAccessResource::getUrl('index'))
            ->assertOk();
    }

    /** Granting temple access is a super-admin decision, not an editor's. */
    public function test_an_editor_cannot_reach_the_access_list(): void
    {
        $this->actingAs($this->editor())
            ->get(TempleAccessResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_the_pending_filter_narrows_the_list(): void
    {
        $this->actingAs($this->superAdmin());
        $temple = $this->temple();

        $pending = TempleUser::create([
            'temple_id' => $temple->id,
            'user_id' => $this->templeAdmin()->id,
            'requested_at' => now(),
        ]);

        $approved = TempleUser::create([
            'temple_id' => $temple->id,
            'user_id' => $this->templeAdmin()->id,
            'requested_at' => now(),
            'approved_at' => now(),
        ]);

        Livewire::test(ListTempleAccess::class)
            ->filterTable('pending')
            ->assertCanSeeTableRecords([$pending])
            ->assertCanNotSeeTableRecords([$approved]);
    }

    /** Staff creating the claim are the verification, so it is live at once. */
    public function test_granting_access_records_the_approval_in_the_same_act(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);

        $temple = $this->temple();
        $account = $this->templeAdmin();

        Livewire::test(ClaimsRelationManager::class, [
            'ownerRecord' => $temple,
            'pageClass' => EditTemple::class,
        ])
            ->callTableAction('create', data: [
                'user_id' => $account->id,
                'role' => 'owner',
                'claim_note' => 'Confirmed by phone with the temple office.',
            ])
            ->assertHasNoActionErrors();

        $claim = TempleUser::firstOrFail();

        $this->assertTrue($claim->isApproved());
        $this->assertSame($admin->id, $claim->approved_by);
        $this->assertNotNull($claim->requested_at);
        $this->assertTrue($account->fresh()->administersTemple($temple));
    }

    /**
     * The fix for "no user to pick": the account is created from the grant
     * form, with the only role that can actually use the seat.
     */
    public function test_a_temple_account_can_be_created_from_the_grant_form(): void
    {
        $this->actingAs($this->superAdmin());

        $field = TempleAccessForm::accountField();

        // The form is what turns an empty dropdown into a way forward.
        $this->assertTrue($field->hasCreateOptionActionFormSchema());

        $create = $field->getCreateOptionUsing();
        $this->assertNotNull($create);

        $id = $create([
            'name' => 'Sri Trust Office',
            'email' => 'office@sritrust.example',
            'password' => 'a-temporary-passphrase',
        ]);

        $created = User::findOrFail($id);

        $this->assertSame('office@sritrust.example', $created->email);
        $this->assertSame(UserRole::TempleAdmin, $created->role);
        $this->assertTrue((bool) $created->is_active);
        $this->assertTrue(Hash::check('a-temporary-passphrase', $created->password));

        // A seat under any other role grants nothing: the portal gate reads
        // the role, not the claim.
        $this->assertTrue($created->canAccessPanel(Filament::getPanel('temple')));
        $this->assertFalse($created->canAccessPanel(Filament::getPanel('admin')));

        // And it is immediately pickable, which is the whole point.
        $this->assertArrayHasKey($created->id, TempleAccessForm::accountField()->getOptions());
    }

    public function test_the_account_dropdown_lists_only_temple_admin_accounts(): void
    {
        $this->superAdmin();
        $this->editor();
        $account = $this->templeAdmin();

        $options = TempleAccessForm::accountField()->getOptions();

        $this->assertSame([$account->id], array_keys($options));
        // The email disambiguates two people with the same name.
        $this->assertStringContainsString($account->email, $options[$account->id]);
    }

    public function test_revoking_access_removes_it_immediately(): void
    {
        $this->actingAs($this->superAdmin());
        $temple = $this->temple();
        $account = $this->templeAdmin();

        $claim = TempleUser::create([
            'temple_id' => $temple->id,
            'user_id' => $account->id,
            'requested_at' => now(),
            'approved_at' => now(),
        ]);

        $this->assertTrue($account->fresh()->administersTemple($temple));

        Livewire::test(ListTempleAccess::class)
            ->callTableAction('revoke', $claim, data: ['rejection_reason' => 'Trustee left the office.']);

        $this->assertFalse($claim->fresh()->isApproved());
        $this->assertFalse($account->fresh()->administersTemple($temple));
    }
}
