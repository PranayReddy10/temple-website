<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temple_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('temple_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // owner  — the trust or temple office that claimed the profile
            // manager — day-to-day staff added by the owner
            $table->string('role')->default('manager');

            /*
             * A claim is not access. Staff verify that the person really
             * represents the temple before approved_at is set, and only an
             * approved link grants the portal any visibility of the temple.
             */
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('claim_note')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->timestamps();

            $table->unique(['temple_id', 'user_id']);
            $table->index(['user_id', 'approved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temple_user');
    }
};
