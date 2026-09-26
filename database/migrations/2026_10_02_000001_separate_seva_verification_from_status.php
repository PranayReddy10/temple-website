<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Verification stops being a stage and becomes a badge.
 *
 * A drive used to go raised → approved → done → verified, so verifying one
 * also finished it: a drive staff verified the week before it happened
 * showed as over. Now where a drive is in its life (open, completed) and
 * whether staff have checked it are separate questions. `verified_at`
 * already records the second; the organiser can ask for it.
 *
 * Donors also say which app they paid with and on what day, so the
 * organiser can find the payment in their statement.
 *
 * Every step checks before it acts, so a deploy that failed half-way through
 * can simply run it again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seva_drives', function (Blueprint $table): void {
            if (! Schema::hasColumn('seva_drives', 'verification_requested_at')) {
                $table->timestamp('verification_requested_at')->nullable();
                $table->text('verification_note')->nullable();
            }
        });

        Schema::table('seva_drive_donations', function (Blueprint $table): void {
            if (! Schema::hasColumn('seva_drive_donations', 'payment_app')) {
                $table->string('payment_app', 24)->nullable()->after('upi_ref');
                $table->date('paid_on')->nullable()->after('payment_app');
            }
        });

        // "Verified" was also "finished". Keep both facts, in their new places.
        DB::table('seva_drives')->where('status', 'verified')->update([
            'status' => 'completed',
            'verified_at' => DB::raw('coalesce(verified_at, updated_at)'),
        ]);
        DB::table('seva_drives')->where('status_before_block', 'verified')->update(['status_before_block' => 'completed']);
    }

    public function down(): void
    {
        Schema::table('seva_drive_donations', function (Blueprint $table): void {
            $table->dropColumn(['payment_app', 'paid_on']);
        });

        Schema::table('seva_drives', function (Blueprint $table): void {
            $table->dropColumn(['verification_requested_at', 'verification_note']);
        });
    }
};
