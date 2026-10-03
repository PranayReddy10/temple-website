<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * What was last read from a temple's own website, waiting for a staff
 * member to check and tick into the listing (OfficialSiteReader).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('temples', function (Blueprint $table) {
            if (! Schema::hasColumn('temples', 'official_import')) {
                $table->json('official_import')->nullable();
                $table->timestamp('official_import_at')->nullable();
                $table->timestamp('official_import_reviewed_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('temples', function (Blueprint $table) {
            $table->dropColumn(['official_import', 'official_import_at', 'official_import_reviewed_at']);
        });
    }
};
