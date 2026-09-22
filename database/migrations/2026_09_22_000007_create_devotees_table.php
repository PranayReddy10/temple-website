<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Devotees are deliberately NOT in the users table.
         *
         * They are expected in the millions against a few hundred staff, they
         * will authenticate by phone OTP or a social provider rather than a
         * password, and they share almost no columns with a staff account.
         *
         * The decisive reason is blast radius: with one table, a single
         * mass-assignment mistake could give a devotee account a staff role
         * and the run of the admin panel. Two tables make that impossible
         * rather than merely unlikely.
         */
        Schema::create('devotees', function (Blueprint $table) {
            $table->id();
            $table->string('name');

            // Either may be the identifier, so both are optional but unique.
            // A devotee who signed up by phone has no email, and vice versa.
            $table->string('email')->nullable()->unique();
            $table->string('phone', 20)->nullable()->unique();

            // Nullable: an OTP or social sign-in never sets one.
            $table->string('password')->nullable();

            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('phone_verified_at')->nullable();

            $table->string('avatar_path')->nullable();
            $table->string('avatar_disk')->nullable();
            $table->string('locale', 10)->default('en');
            $table->foreignId('home_state_id')->nullable()->constrained('states')->nullOnDelete();
            $table->date('date_of_birth')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamp('last_seen_at')->nullable();

            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            $table->index('is_active');
        });

        Schema::create('devotee_saved_temples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('devotee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('temple_id')->constrained()->cascadeOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['devotee_id', 'temple_id']);
            $table->index(['devotee_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devotee_saved_temples');
        Schema::dropIfExists('devotees');
    }
};
