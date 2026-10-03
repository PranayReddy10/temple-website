<?php

namespace Tests\Feature\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The verification history migration failed its first MySQL deploy: the
 * table was created, then a foreign key name past 64 characters was
 * refused, and MySQL kept the half-made table. Running it again must finish
 * the job, and no name it uses may be too long for MySQL.
 */
class VerificationEventsMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function migration(): object
    {
        return require database_path('migrations/2026_10_16_000001_create_temple_payout_verification_events.php');
    }

    public function test_it_finishes_a_table_a_failed_deploy_left_behind(): void
    {
        Schema::drop('temple_payout_verification_events');
        // As the failed deploy left it: columns, no keys, no indexes.
        Schema::create('temple_payout_verification_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('temple_payout_account_id');
            $table->unsignedBigInteger('temple_id');
            $table->string('event', 30);
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->json('snapshot')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        $this->migration()->up();

        $this->assertEqualsCanonicalizing(
            ['temple_payout_account_id', 'temple_id', 'user_id'],
            collect(Schema::getForeignKeys('temple_payout_verification_events'))->flatMap(fn ($fk) => $fk['columns'])->all(),
        );
        $this->assertContains('tpve_account_created_index', collect(Schema::getIndexes('temple_payout_verification_events'))->pluck('name')->all());
    }

    public function test_running_it_again_changes_nothing(): void
    {
        $this->migration()->up();

        $this->assertCount(3, Schema::getForeignKeys('temple_payout_verification_events'));
    }

    /** MySQL refuses identifiers over 64 characters; catch one here, not on deploy. */
    public function test_no_index_name_is_too_long_for_mysql(): void
    {
        $long = collect(Schema::getTableListing())
            ->flatMap(fn (string $table) => collect(Schema::getIndexes(str_contains($table, '.') ? substr($table, strpos($table, '.') + 1) : $table))->pluck('name'))
            ->filter(fn (string $name) => strlen($name) > 64)
            ->values()
            ->all();

        $this->assertSame([], $long);
    }
}
