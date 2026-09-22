<?php

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DeployCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Puts the database in the state a half-finished deploy leaves behind:
     * the table is gone AND its migration is no longer recorded as run.
     *
     * Dropping the table alone is not enough — the migrations row would still
     * say it ran, so nothing would be pending, which is not the situation
     * that took the live panel down.
     */
    protected function makePending(string $table, string $migrationSuffix): void
    {
        Schema::dropIfExists($table);
        DB::table('migrations')->where('migration', 'like', '%'.$migrationSuffix)->delete();
    }

    public function test_it_reports_a_current_database_as_up_to_date(): void
    {
        $this->artisan('app:deploy', ['--check' => true])
            ->expectsOutputToContain('up to date')
            ->assertSuccessful();
    }

    public function test_check_mode_changes_nothing(): void
    {
        $this->makePending('settings', 'create_settings_table');

        $this->artisan('app:deploy', ['--check' => true])
            ->expectsOutputToContain('pending')
            ->assertSuccessful();

        // The whole point of --check: report, never act.
        $this->assertFalse(Schema::hasTable('settings'));
    }

    public function test_it_lists_the_migrations_that_are_pending(): void
    {
        $this->makePending('temple_user', 'create_temple_user_table');

        // This is the exact failure that took the live admin panel down:
        // new code deployed, migration never run, "table doesn't exist".
        $this->artisan('app:deploy', ['--check' => true])
            ->expectsOutputToContain('create_temple_user_table')
            ->assertSuccessful();
    }

    public function test_it_runs_pending_migrations_when_forced(): void
    {
        $this->makePending('temple_user', 'create_temple_user_table');
        $this->assertFalse(Schema::hasTable('temple_user'));

        $this->artisan('app:deploy', ['--force' => true])->assertSuccessful();

        $this->assertTrue(Schema::hasTable('temple_user'));
    }

    public function test_it_stops_when_the_operator_declines(): void
    {
        $this->makePending('temple_user', 'create_temple_user_table');

        $this->artisan('app:deploy')
            ->expectsConfirmation('Run these migrations now?', 'no')
            ->assertFailed();

        $this->assertFalse(Schema::hasTable('temple_user'));
    }

    public function test_it_leaves_existing_data_alone(): void
    {
        $temple = \App\Models\Temple::create(['name' => 'Survivor Temple']);
        $this->makePending('temple_user', 'create_temple_user_table');

        $this->artisan('app:deploy', ['--force' => true])->assertSuccessful();

        // A deploy that lost data would be worse than the error it fixes.
        $this->assertDatabaseHas('temples', ['id' => $temple->id, 'name' => 'Survivor Temple']);
    }
}
