<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use App\Filament\Pages\ManageSettings;
use Livewire\Livewire;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_stored_setting_wins_over_config(): void
    {
        config(['brand.name' => 'From Env']);
        Setting::set('brand_name', 'From Settings');

        $this->assertSame('From Settings', setting('brand_name', 'brand.name'));
    }

    public function test_it_falls_back_to_config_when_unset(): void
    {
        config(['brand.name' => 'From Env']);

        $this->assertSame('From Env', setting('brand_name', 'brand.name'));
    }

    public function test_clearing_a_setting_falls_back_rather_than_blanking(): void
    {
        config(['brand.name' => 'From Env']);
        Setting::set('brand_name', 'From Settings');
        Setting::set('brand_name', null);

        // An empty brand name would take the site's name away everywhere.
        $this->assertSame('From Env', setting('brand_name', 'brand.name'));
    }

    public function test_an_empty_string_also_falls_back(): void
    {
        config(['brand.name' => 'From Env']);
        Setting::set('brand_name', '');

        $this->assertSame('From Env', setting('brand_name', 'brand.name'));
    }

    public function test_booleans_round_trip(): void
    {
        Setting::set('community_submissions_enabled', '1', 'boolean');
        $this->assertTrue(Setting::get('community_submissions_enabled'));

        Setting::set('community_submissions_enabled', '0', 'boolean');
        $this->assertFalse(Setting::get('community_submissions_enabled'));
    }

    public function test_integers_and_json_round_trip(): void
    {
        Setting::set('page_size', '25', 'integer');
        $this->assertSame(25, Setting::get('page_size'));

        Setting::set('locales', ['en', 'te'], 'json');
        $this->assertSame(['en', 'te'], Setting::get('locales'));
    }

    public function test_the_cache_is_flushed_on_write(): void
    {
        Setting::set('brand_name', 'First');
        $this->assertSame('First', Setting::get('brand_name'));

        // A stale cache here would make the settings screen look broken.
        Setting::set('brand_name', 'Second');
        $this->assertSame('Second', Setting::get('brand_name'));
    }

    public function test_reads_survive_a_missing_settings_table(): void
    {
        // Renamed rather than dropped, and restored in a finally: schema
        // changes are not rolled back with the surrounding transaction, so a
        // dropped table would stay dropped for every later test in this class.
        Schema::rename('settings', 'settings_backup');
        Setting::flush();

        try {
            // Settings are read while the panel boots, which happens during
            // migrate on a fresh database. Throwing here would make the app
            // impossible to install.
            $this->assertNull(Setting::get('brand_name'));
            $this->assertSame('fallback', setting('brand_name', null, 'fallback'));
        } finally {
            Schema::rename('settings_backup', 'settings');
            Setting::flush();
        }
    }

    public function test_the_admin_brand_name_follows_the_setting(): void
    {
        Setting::set('brand_name', 'Renamed In Panel');

        // Not signed in: an authenticated visitor is redirected away from the
        // login page, and assertSee against a redirect body proves nothing.
        $this->get('/admin/login')->assertSee('Renamed In Panel', escape: false);
    }

    public function test_saving_the_settings_form_persists_every_field(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin]));

        Livewire::test(ManageSettings::class)
            ->fillForm([
                'brand_name' => 'Divya Yatra',
                'brand_tagline' => 'Walk the sacred path',
                'support_email' => 'help@example.com',
                'community_submissions_enabled' => true,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        // Exercises the real save path. An earlier version rendered correctly
        // and stored nothing, which every assertion short of this one passed.
        $this->assertSame('Divya Yatra', Setting::get('brand_name'));
        $this->assertSame('Walk the sacred path', Setting::get('brand_tagline'));
        $this->assertSame('help@example.com', Setting::get('support_email'));
        $this->assertTrue(Setting::get('community_submissions_enabled'));
    }

    public function test_clearing_a_field_through_the_form_restores_the_config_value(): void
    {
        config(['brand.name' => 'From Env']);
        Setting::set('brand_name', 'Temporary Name');

        $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin]));

        Livewire::test(ManageSettings::class)
            ->fillForm(['brand_name' => ''])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('From Env', setting('brand_name', 'brand.name'));
    }

    public function test_an_editor_cannot_render_the_settings_form(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Editor]));

        $this->assertFalse(ManageSettings::canAccess());
    }

    public function test_only_a_super_admin_can_reach_the_settings_screen(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin]))
            ->get('/admin/manage-settings')
            ->assertOk();

        $this->actingAs(User::factory()->create(['role' => UserRole::Editor]))
            ->get('/admin/manage-settings')
            ->assertForbidden();
    }
}
