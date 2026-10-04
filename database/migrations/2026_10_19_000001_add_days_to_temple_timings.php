<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * A timing can hold for several days ("Mon–Fri", "Sat & Sun"), not only
 * every day or one day. `days` is the list (0 = Sunday … 6 = Saturday,
 * null = every day); `day_of_week` stays, filled from it, for app versions
 * that read only that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('temple_timings', function (Blueprint $table) {
            if (! Schema::hasColumn('temple_timings', 'days')) {
                $table->json('days')->nullable()->after('day_of_week');
            }
        });

        DB::table('temple_timings')->whereNotNull('day_of_week')->whereNull('days')->orderBy('id')
            ->each(fn ($row) => DB::table('temple_timings')->where('id', $row->id)->update(['days' => json_encode([(int) $row->day_of_week])]));
    }

    public function down(): void
    {
        Schema::table('temple_timings', function (Blueprint $table) {
            $table->dropColumn('days');
        });
    }
};
