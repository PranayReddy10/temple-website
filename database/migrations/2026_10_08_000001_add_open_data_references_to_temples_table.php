<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Guarded column by column: MySQL does not roll back DDL, so a run
        // that fails part-way must be safe to repeat.
        Schema::table('temples', function (Blueprint $table) {
            // Where an imported temple came from on OpenStreetMap, e.g.
            // "way/123456". Re-running the import matches on this first, so a
            // temple is never created twice however its name is spelled.
            if (! Schema::hasColumn('temples', 'osm_ref')) {
                $table->string('osm_ref', 40)->nullable()->unique()->after('source_url');
            }

            // The Wikidata item, e.g. "Q3635467". Used to find a freely
            // licensed photograph on Wikimedia Commons.
            if (! Schema::hasColumn('temples', 'wikidata_id')) {
                $table->string('wikidata_id', 20)->nullable()->index()->after('source_url');
            }

            // A Commons file OpenStreetMap names as this temple's picture,
            // e.g. "File:Ramappa temple.jpg". A lead, not yet a photo: the
            // photo step checks its licence before anything is shown.
            if (! Schema::hasColumn('temples', 'commons_image')) {
                $table->string('commons_image')->nullable()->after('source_url');
            }
        });
    }

    public function down(): void
    {
        Schema::table('temples', function (Blueprint $table) {
            $table->dropUnique(['osm_ref']);
            $table->dropIndex(['wikidata_id']);
            $table->dropColumn(['osm_ref', 'wikidata_id', 'commons_image']);
        });
    }
};
