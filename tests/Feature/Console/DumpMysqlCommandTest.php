<?php

namespace Tests\Feature\Console;

use App\Models\Deity;
use App\Models\State;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class DumpMysqlCommandTest extends TestCase
{
    use RefreshDatabase;

    protected string $output = 'database/dumps/test-dump.sql';

    protected function tearDown(): void
    {
        File::delete(base_path($this->output));

        parent::tearDown();
    }

    protected function dump(array $options = []): string
    {
        $this->artisan('db:mysql-dump', array_merge(['--output' => $this->output], $options))
            ->assertSuccessful();

        return File::get(base_path($this->output));
    }

    public function test_it_renders_mysql_ddl_not_the_local_sqlite_dialect(): void
    {
        $sql = $this->dump();

        // MySQL backticks and charset clause, which SQLite never emits.
        $this->assertStringContainsString('create table `temples`', $sql);
        $this->assertStringContainsString('default character set utf8mb4', $sql);
        $this->assertStringContainsString('bigint unsigned not null auto_increment', $sql);
    }

    public function test_it_creates_the_migrations_table_it_writes_history_into(): void
    {
        $sql = $this->dump();

        // No migration file defines this table; Laravel's migrator does. Without
        // it the import fails on "Table 'migrations' doesn't exist".
        $this->assertStringContainsString('create table `migrations`', $sql);

        $createPos = strpos($sql, 'create table `migrations`');
        $insertPos = strpos($sql, 'INSERT INTO `migrations`');

        $this->assertNotFalse($insertPos);
        $this->assertLessThan($insertPos, $createPos, 'History is inserted before the table exists.');
    }

    public function test_it_records_every_migration_as_already_run(): void
    {
        $sql = $this->dump();

        // Otherwise a later `php artisan migrate` would try to recreate tables
        // the import already made.
        foreach (['create_temples_table', 'create_temple_pujas_table', 'create_facilities_table'] as $migration) {
            $this->assertStringContainsString($migration, $sql);
        }
    }

    public function test_it_exports_seeded_reference_data(): void
    {
        State::create(['name' => 'Telangana', 'slug' => 'telangana', 'code' => 'TG']);
        Deity::create(['name' => 'Shiva', 'slug' => 'shiva']);

        $sql = $this->dump();

        $this->assertStringContainsString('INSERT INTO `states`', $sql);
        $this->assertStringContainsString('Telangana', $sql);
        $this->assertStringContainsString('INSERT INTO `deities`', $sql);
    }

    public function test_schema_only_omits_data(): void
    {
        State::create(['name' => 'Telangana', 'slug' => 'telangana', 'code' => 'TG']);

        $sql = $this->dump(['--schema-only' => true]);

        $this->assertStringContainsString('create table `states`', $sql);
        $this->assertStringNotContainsString('INSERT INTO `states`', $sql);
    }

    public function test_it_escapes_quotes_so_the_import_cannot_break(): void
    {
        Deity::create(['name' => "Shiva's Temple", 'slug' => 'quote-test']);

        $sql = $this->dump();

        // An unescaped apostrophe would terminate the string literal and
        // corrupt every statement after it.
        $this->assertStringContainsString("Shiva\\'s Temple", $sql);
    }

    public function test_it_preserves_non_latin_text(): void
    {
        Deity::create(['name' => 'వెంకటేశ్వర', 'slug' => 'telugu-test']);

        $sql = $this->dump();

        $this->assertStringContainsString('వెంకటేశ్వర', $sql);
        $this->assertStringContainsString('SET NAMES utf8mb4', $sql);
    }

    public function test_it_leaves_the_application_connection_usable_afterwards(): void
    {
        State::create(['name' => 'Telangana', 'slug' => 'telangana', 'code' => 'TG']);

        $this->dump();

        // Rendering swaps the default connection and injects a stub PDO. An
        // earlier version leaked that stub onto the shared connection, which
        // broke the command outright on a MySQL-backed app.
        $this->assertSame(config('database.default'), DB::getDefaultConnection());
        $this->assertSame(1, State::count());
    }
}
