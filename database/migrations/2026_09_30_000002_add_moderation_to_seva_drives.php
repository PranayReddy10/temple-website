<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Seva drives, run by staff as well as devotees, and moderated after the fact.
 *
 * - A drive staff create themselves may have no devotee behind it, so the
 *   organiser becomes optional and a display name can stand in for one.
 * - Blocking takes a drive down for everybody but staff, and remembers the
 *   status it had so unblocking puts it back where it was.
 * - Marking a drive misleading leaves it visible — people who joined need to
 *   see what happened — with a warning, and closes joining and donations.
 *
 * Every step checks before it acts, so a deploy that failed half-way through
 * can simply run it again (MySQL does not roll back DDL).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seva_drives', function (Blueprint $table): void {
            $table->unsignedBigInteger('devotee_id')->nullable()->change();

            if (! Schema::hasColumn('seva_drives', 'organiser_name')) {
                $table->string('organiser_name', 120)->nullable()->after('devotee_id');
            }
            if (! Schema::hasColumn('seva_drives', 'created_by')) {
                $table->foreignId('created_by')->nullable()->after('organiser_name')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('seva_drives', 'blocked_at')) {
                $table->timestamp('blocked_at')->nullable();
                $table->foreignId('blocked_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('block_reason')->nullable();
                $table->string('status_before_block', 16)->nullable();
            }
            if (! Schema::hasColumn('seva_drives', 'is_misleading')) {
                $table->boolean('is_misleading')->default(false);
                $table->text('misleading_note')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('seva_drives', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('blocked_by');
            $table->dropColumn(['organiser_name', 'blocked_at', 'block_reason', 'status_before_block', 'is_misleading', 'misleading_note']);
        });
    }
};
