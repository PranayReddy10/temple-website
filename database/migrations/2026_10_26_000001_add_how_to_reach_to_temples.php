<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * How a pilgrim gets there: the nearest railway station, airport and bus
 * station. Devotees plan journeys around it, and "how to reach <temple>"
 * is one of the questions a temple page is searched for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('temples', function (Blueprint $table) {
            if (! Schema::hasColumn('temples', 'how_to_reach')) {
                $table->text('how_to_reach')->nullable()->after('queue_information');
            }
        });
    }

    public function down(): void
    {
        Schema::table('temples', function (Blueprint $table) {
            $table->dropColumn('how_to_reach');
        });
    }
};
