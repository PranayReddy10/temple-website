<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Which of a temple's texts were taken from its Wikipedia article
 * ("history", "significance"; the description has description_source).
 * Each is shown with "From Wikipedia, CC BY-SA 4.0"; an editor rewriting
 * one takes it off the list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('temples', function (Blueprint $table) {
            if (! Schema::hasColumn('temples', 'wikipedia_fields')) {
                $table->json('wikipedia_fields')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('temples', function (Blueprint $table) {
            $table->dropColumn('wikipedia_fields');
        });
    }
};
