<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A Google Maps link is read for its pin and name when a temple is
 * imported; the coordinates are what is kept. The link itself (often a
 * thousand characters of tracking parameters) is not.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('temples', 'google_maps_url')) {
            Schema::table('temples', function (Blueprint $table) {
                $table->dropColumn('google_maps_url');
            });
        }
    }

    public function down(): void
    {
        Schema::table('temples', function (Blueprint $table) {
            if (! Schema::hasColumn('temples', 'google_maps_url')) {
                $table->string('google_maps_url', 2048)->nullable();
            }
        });
    }
};
