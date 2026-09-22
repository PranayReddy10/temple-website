<?php

namespace Tests\Feature\Console;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DeployCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * These tests are about what a deploy does to a POPULATED database, so
     * they seed explicitly in setUp.
     *
     * The $seed property is not enough: RefreshDatabase migrates once per test
     * run and seeds only on that first migration, so whichever class happens
     * to run first decides whether this one sees any data. Seeding here runs
     * inside each test's transaction and does not depend on ordering.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

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

    public function test_it_seeds_reference_data_a_release_introduced(): void
    {
        DB::table('devotional_days')->delete();

        $this->artisan('app:deploy', ['--force' => true])->assertSuccessful();

        // A release ships the table through a migration and the rows through a
        // seeder. Migrating alone would leave daily devotion silently empty.
        $this->assertGreaterThan(0, DB::table('devotional_days')->count());
    }

    public function test_it_reports_missing_reference_data_in_check_mode(): void
    {
        DB::table('devotional_days')->delete();

        $this->artisan('app:deploy', ['--check' => true])
            ->expectsOutputToContain('weekday-to-deity mapping')
            ->assertSuccessful();

        $this->assertSame(0, DB::table('devotional_days')->count());
    }

    public function test_it_never_overwrites_reference_data_an_editor_changed(): void
    {
        $day = DB::table('devotional_days')->first();
        DB::table('devotional_days')->where('id', $day->id)->update([
            'mantra_transliteration' => 'Editor customised',
            'accent_color' => '#123456',
        ]);

        $this->artisan('app:deploy', ['--force' => true])->assertSuccessful();

        // The seeders use updateOrCreate, so re-running a populated table would
        // quietly revert a customised mantra or colour to the shipped default.
        $after = DB::table('devotional_days')->where('id', $day->id)->first();
        $this->assertSame('Editor customised', $after->mantra_transliteration);
        $this->assertSame('#123456', $after->accent_color);
    }

    public function test_it_does_not_reseed_sample_temples(): void
    {
        $before = DB::table('temples')->count();
        DB::table('temples')->delete();

        $this->artisan('app:deploy', ['--force' => true])->assertSuccessful();

        // Sample data is excluded on purpose: re-running it in production
        // would reset the verification level of records an editor has checked.
        $this->assertGreaterThan(0, $before);
        $this->assertSame(0, DB::table('temples')->count());
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
