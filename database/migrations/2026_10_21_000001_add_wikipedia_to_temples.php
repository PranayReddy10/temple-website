<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A temple's Wikipedia article, and where its description came from. Text
 * taken from Wikipedia is CC BY-SA: wherever it is shown, it says so and
 * links the article. An editor rewriting it makes it ours again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('temples', function (Blueprint $table) {
            if (! Schema::hasColumn('temples', 'wikipedia_url')) {
                $table->string('wikipedia_url', 500)->nullable();
            }
            if (! Schema::hasColumn('temples', 'description_source')) {
                // null: written by us; "wikipedia": the article's opening.
                $table->string('description_source', 20)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('temples', function (Blueprint $table) {
            $table->dropColumn(['wikipedia_url', 'description_source']);
        });
    }
};
