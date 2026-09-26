<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What devotees add to a temple: a like, a follow, an account of a visit,
 * and their photographs promoted into its gallery.
 *
 * Three signals of interest, kept apart on purpose. A like is the lightest
 * and says nothing else. A save (already here) is a bookmark for oneself. A
 * follow is a request to be told things, so it carries what to be told
 * about, per temple, and nothing is sent to anyone who has not asked.
 *
 * Every step checks before it acts, so a deploy that failed half-way through
 * can simply run it again (MySQL does not roll back DDL).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('temple_likes')) {
            Schema::create('temple_likes', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('devotee_id')->constrained()->cascadeOnDelete();
                $table->foreignId('temple_id')->constrained()->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['devotee_id', 'temple_id']);
                $table->index('temple_id');
            });
        }

        if (! Schema::hasTable('temple_follows')) {
            Schema::create('temple_follows', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('devotee_id')->constrained()->cascadeOnDelete();
                $table->foreignId('temple_id')->constrained()->cascadeOnDelete();
                // Reminders are opt-in per temple: following is asking to be
                // told, and each of these is one thing to be told about.
                $table->boolean('notify_festivals')->default(true);
                $table->boolean('notify_events')->default(true);
                $table->timestamps();
                $table->unique(['devotee_id', 'temple_id']);
                $table->index('temple_id');
            });
        }

        if (! Schema::hasTable('temple_reviews')) {
            Schema::create('temple_reviews', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('devotee_id')->constrained()->cascadeOnDelete();
                $table->foreignId('temple_id')->constrained()->cascadeOnDelete();
                $table->foreignId('devotee_visit_id')->nullable()->constrained('devotee_visits')->nullOnDelete();
                $table->date('visited_on');

                /*
                 * The visit is rated, never the temple. Each is 1 to 5 and
                 * optional: a devotee who only wants to say the queue was
                 * long should not have to score the toilets.
                 */
                $table->unsignedTinyInteger('queue_rating')->nullable();
                $table->unsignedTinyInteger('cleanliness_rating')->nullable();
                $table->unsignedTinyInteger('facilities_rating')->nullable();
                $table->unsignedTinyInteger('accessibility_rating')->nullable();
                $table->unsignedTinyInteger('accuracy_rating')->nullable();
                // How long they waited for darshan, in minutes, as they remember it.
                $table->unsignedSmallInteger('wait_minutes')->nullable();

                $table->text('body')->nullable();

                // pending | approved | rejected, moderated like photos are.
                $table->string('status', 16)->default('pending');
                $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('moderated_at')->nullable();
                $table->text('moderation_note')->nullable();

                // The temple's own answer, from its portal.
                $table->text('temple_reply')->nullable();
                $table->timestamp('temple_replied_at')->nullable();
                $table->foreignId('temple_replied_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['devotee_id', 'temple_id', 'visited_on']);
                $table->index(['temple_id', 'status', 'created_at']);
                $table->index(['status', 'created_at']);
            });
        }

        Schema::table('temple_photos', function (Blueprint $table): void {
            // A photo promoted from a devotee's Photo Stamp: who to credit,
            // which upload it came from, and whether the temple objected.
            if (! Schema::hasColumn('temple_photos', 'devotee_id')) {
                $table->foreignId('devotee_id')->nullable()->after('uploaded_by')->constrained()->nullOnDelete();
            }
            if (! Schema::hasColumn('temple_photos', 'visit_photo_id')) {
                $table->foreignId('visit_photo_id')->nullable()->unique()->after('devotee_id')->constrained('visit_photos')->nullOnDelete();
            }
            if (! Schema::hasColumn('temple_photos', 'temple_objected_at')) {
                $table->timestamp('temple_objected_at')->nullable()->after('visit_photo_id');
            }
            if (! Schema::hasColumn('temple_photos', 'temple_objection')) {
                $table->string('temple_objection')->nullable()->after('temple_objected_at');
            }
        });

        Schema::table('app_notifications', function (Blueprint $table): void {
            // What generated an automatic notification (an event reminder),
            // so the daily run never sends the same one twice.
            if (! Schema::hasColumn('app_notifications', 'source_key')) {
                $table->string('source_key', 80)->nullable()->unique()->after('platform');
            }
        });
    }

    public function down(): void
    {
        Schema::table('app_notifications', function (Blueprint $table): void {
            $table->dropUnique(['source_key']);
            $table->dropColumn('source_key');
        });

        Schema::table('temple_photos', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('visit_photo_id');
            $table->dropConstrainedForeignId('devotee_id');
            $table->dropColumn(['temple_objected_at', 'temple_objection']);
        });

        Schema::dropIfExists('temple_reviews');
        Schema::dropIfExists('temple_follows');
        Schema::dropIfExists('temple_likes');
    }
};
