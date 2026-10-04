<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The temple's Google Maps link as staff pasted it: where its pin came
 * from, and how the same place is known again on a later import.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('temples', function (Blueprint $table) {
            if (! Schema::hasColumn('temples', 'google_maps_url')) {
                $table->string('google_maps_url', 2048)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('temples', function (Blueprint $table) {
            $table->dropColumn('google_maps_url');
        });
    }
};
