<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Other ways people search for a temple ("bhongir temple", "manepally
 * hills temple"), entered in the admin. Comma-separated text rather than
 * JSON, so a Telugu keyword is stored as written and found by LIKE.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('temples', function (Blueprint $table) {
            if (! Schema::hasColumn('temples', 'search_keywords')) {
                $table->text('search_keywords')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('temples', function (Blueprint $table) {
            $table->dropColumn('search_keywords');
        });
    }
};
