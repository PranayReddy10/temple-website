<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Every step of a temple's payment verification, kept for good: each time
 * the owner sent documents, each approval and each rejection with its
 * reason. The snapshot holds what was sent at that moment, including the
 * document files, so a rejected set can still be looked at after the owner
 * has replaced it, and a person who tries again with different papers shows.
 *
 * Keys and indexes are named by hand: the names Laravel derives from this
 * table's name run past MySQL's 64-character limit. The first deploy failed
 * on exactly that after the table had been created, and MySQL does not roll
 * DDL back, so each step here checks before it acts and running it again
 * finishes the job.
 */
return new class extends Migration
{
    protected string $table = 'temple_payout_verification_events';

    public function up(): void
    {
        if (! Schema::hasTable($this->table)) {
            Schema::create($this->table, function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('temple_payout_account_id');
                $table->unsignedBigInteger('temple_id');
                // submitted · approved · rejected · bank_changed · approval_removed
                $table->string('event', 30);
                $table->text('reason')->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->json('snapshot')->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }

        $indexes = collect(Schema::getIndexes($this->table))->pluck('name')->map(fn ($n) => strtolower($n));
        $foreign = collect(Schema::getForeignKeys($this->table))->flatMap(fn (array $fk) => $fk['columns']);

        Schema::table($this->table, function (Blueprint $table) use ($indexes, $foreign) {
            if (! $foreign->contains('temple_payout_account_id')) {
                $table->foreign('temple_payout_account_id', 'tpve_account_fk')->references('id')->on('temple_payout_accounts')->cascadeOnDelete();
            }
            if (! $foreign->contains('temple_id')) {
                $table->foreign('temple_id', 'tpve_temple_fk')->references('id')->on('temples')->cascadeOnDelete();
            }
            if (! $foreign->contains('user_id')) {
                $table->foreign('user_id', 'tpve_user_fk')->references('id')->on('users')->nullOnDelete();
            }
            if (! $indexes->contains('tpve_account_created_index')) {
                $table->index(['temple_payout_account_id', 'created_at'], 'tpve_account_created_index');
            }
            if (! $indexes->contains('tpve_event_created_index')) {
                $table->index(['event', 'created_at'], 'tpve_event_created_index');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table);
    }
};
