<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * A devotee's own passport code, visits marked by temple staff, and memory
 * photos.
 *
 * The passport code is a random token rather than the devotee's id: an id is
 * guessable and permanent, and a code shown to a stranger at a temple counter
 * has to be neither. Resetting it retires every copy already printed or
 * photographed.
 *
 * `verified_by` records which temple staff member marked a visit, so a stamp
 * given at a counter can be traced to the person who gave it.
 *
 * `kind` separates the one photo that goes in the passport from the memory
 * photos kept alongside a visit. Memory photos are never shown to anyone
 * else, so they never enter the moderation queue.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('devotees', 'passport_code')) {
            Schema::table('devotees', function (Blueprint $table): void {
                $table->string('passport_code', 32)->nullable()->unique()->after('gender');
            });
        }

        // By id, not by offset: the rows being updated are the ones filtered on.
        DB::table('devotees')->whereNull('passport_code')->lazyById()->each(function (object $row): void {
            DB::table('devotees')->where('id', $row->id)->update(['passport_code' => Str::random(20)]);
        });

        if (! Schema::hasColumn('devotee_visits', 'verified_by')) {
            Schema::table('devotee_visits', function (Blueprint $table): void {
                $table->foreignId('verified_by')->nullable()->after('verified_at')
                    ->constrained('users')->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('visit_photos', 'kind')) {
            Schema::table('visit_photos', function (Blueprint $table): void {
                // stamp  — the photo in the passport, shareable once approved
                // memory — up to three more per visit, private to the devotee
                $table->string('kind', 16)->default('stamp')->after('devotee_visit_id');
                $table->index(['devotee_visit_id', 'kind']);
            });
        }
    }

    public function down(): void
    {
        Schema::table('visit_photos', function (Blueprint $table): void {
            $table->dropIndex(['devotee_visit_id', 'kind']);
            $table->dropColumn('kind');
        });

        Schema::table('devotee_visits', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('verified_by');
        });

        Schema::table('devotees', function (Blueprint $table): void {
            $table->dropUnique(['passport_code']);
            $table->dropColumn('passport_code');
        });
    }
};
