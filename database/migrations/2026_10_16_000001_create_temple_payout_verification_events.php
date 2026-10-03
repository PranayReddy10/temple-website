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
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temple_payout_verification_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('temple_payout_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('temple_id')->constrained()->cascadeOnDelete();
            // submitted · approved · rejected · bank_changed · approval_removed
            $table->string('event', 30);
            $table->text('reason')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->json('snapshot')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['temple_payout_account_id', 'created_at']);
            $table->index(['event', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temple_payout_verification_events');
    }
};
