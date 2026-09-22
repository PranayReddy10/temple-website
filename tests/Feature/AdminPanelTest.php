<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\DeitySeeder;
use Database\Seeders\StateSeeder;
use Database\Seeders\TempleCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::factory()->create(['role' => UserRole::SuperAdmin, 'is_active' => true]);
    }

    protected function editor(): User
    {
        return User::factory()->create(['role' => UserRole::Editor, 'is_active' => true]);
    }

    public function test_the_login_page_is_reachable(): void
    {
        $this->get('/admin/login')->assertOk();
    }

    public function test_guests_are_redirected_away_from_the_panel(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_an_active_user_can_reach_the_dashboard(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin')
            ->assertOk();
    }

    public function test_a_deactivated_user_cannot_reach_the_panel(): void
    {
        $suspended = User::factory()->create(['role' => UserRole::Editor, 'is_active' => false]);

        $this->actingAs($suspended)
            ->get('/admin')
            ->assertForbidden();
    }

    public function test_the_core_resource_pages_render(): void
    {
        $this->seed([StateSeeder::class, DeitySeeder::class, TempleCategorySeeder::class]);

        $this->actingAs($this->admin());

        foreach ([
            '/admin/temples',
            '/admin/temples/create',
            '/admin/deities',
            '/admin/temple-categories',
            '/admin/states',
            '/admin/districts',
        ] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_the_temple_edit_page_renders_with_its_relation_managers(): void
    {
        $this->seed([StateSeeder::class, DeitySeeder::class, TempleCategorySeeder::class]);
        $temple = \App\Models\Temple::create(['name' => 'Relation Temple']);

        // Photos, timings, pujas and closures all hang off this page.
        $this->actingAs($this->admin())
            ->get("/admin/temples/{$temple->id}/edit")
            ->assertOk();
    }

    public function test_a_user_with_no_stored_active_flag_is_denied_not_crashed(): void
    {
        $user = User::factory()->create(['role' => UserRole::SuperAdmin]);

        // is_active comes from a database default, so it is null on the
        // in-memory instance. Returning that from a bool-typed method threw a
        // TypeError and produced a 500 where a 403 belongs.
        $user->setAttribute('is_active', null);

        $this->assertFalse($user->canAccessPanel(app(\Filament\Panel::class)));
    }

    public function test_a_new_user_is_active_by_default(): void
    {
        $user = new User(['name' => 'Fresh', 'email' => 'fresh@example.com']);

        $this->assertTrue($user->is_active);
    }

    public function test_only_a_super_admin_may_manage_users(): void
    {
        $this->actingAs($this->admin())->get('/admin/users')->assertOk();

        // User management is hidden from editors entirely, not merely read-only.
        $this->actingAs($this->editor())->get('/admin/users')->assertForbidden();
    }

    public function test_the_login_page_carries_the_configured_brand_name(): void
    {
        config(['brand.name' => 'Some Other Name']);

        $this->get('/admin/login')->assertSee('Some Other Name', escape: false);
    }
}
