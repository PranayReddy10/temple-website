<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Temples devotees and temple members add from the app.
 *
 * No catalogue lists every temple; a village shrine or a family temple is
 * known to the people who go there, not to us. A suggestion is kept apart
 * from `temples` until staff have looked at it — a suggestion with a typo'd
 * name or an existing temple under another name should never reach search —
 * and becomes a temple (or is matched to one) from the admin.
 *
 * Who sent it matters: a trustee, priest or committee member is the person
 * the temple portal will later be handed to, so their role and a phone
 * number are kept with the suggestion.
 *
 * Every step checks before it acts, so a deploy that failed half-way through
 * can simply run it again.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('temple_suggestions')) {
            Schema::create('temple_suggestions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('devotee_id')->nullable()->constrained()->nullOnDelete();

                $table->string('name');
                $table->string('alternate_names')->nullable();
                $table->foreignId('deity_id')->nullable()->constrained()->nullOnDelete();
                $table->string('deity_name', 120)->nullable();

                $table->string('address')->nullable();
                $table->string('pincode', 6)->nullable();
                $table->string('city', 120)->nullable();
                $table->string('district', 120)->nullable();
                $table->foreignId('state_id')->nullable()->constrained()->nullOnDelete();
                $table->decimal('latitude', 10, 7)->nullable();
                $table->decimal('longitude', 10, 7)->nullable();

                $table->text('description')->nullable();
                $table->text('history')->nullable();
                $table->string('built_period', 120)->nullable();
                $table->text('festivals')->nullable();

                $table->time('opens_at')->nullable();
                $table->time('closes_at')->nullable();
                $table->string('timings_note')->nullable();

                $table->string('contact_phone', 20)->nullable();
                $table->string('official_website')->nullable();

                // devotee | trustee | priest | committee | staff | other
                $table->string('submitter_role', 16)->default('devotee');
                $table->string('submitter_name', 120)->nullable();
                $table->string('submitter_phone', 20)->nullable();
                $table->text('submitter_note')->nullable();

                // pending | approved | duplicate | rejected
                $table->string('status', 16)->default('pending');
                $table->text('review_note')->nullable();
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('reviewed_at')->nullable();

                // The temple it became, or the one it turned out to be.
                $table->foreignId('temple_id')->nullable()->constrained()->nullOnDelete();

                $table->timestamps();

                $table->index(['status', 'created_at']);
                $table->index(['devotee_id', 'created_at']);
            });
        }

        if (! Schema::hasTable('temple_suggestion_photos')) {
            Schema::create('temple_suggestion_photos', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('temple_suggestion_id')->constrained()->cascadeOnDelete();
                $table->string('disk')->nullable();
                $table->string('path');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('temple_suggestion_photos');
        Schema::dropIfExists('temple_suggestions');
    }
};
