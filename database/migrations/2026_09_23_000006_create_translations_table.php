<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Translated field values, one row per field per language.
         *
         * Not columns. `name_te`, `name_hi`, `name_ta`… means a migration and
         * a form change every time a language is added, across every
         * translatable table, and India has more languages than that approach
         * survives. A row per value adds a language by inserting rows.
         *
         * Not a JSON column either: the admin needs "which temples are
         * missing a Telugu description", which is a query over values, and a
         * JSON blob answers it only by reading every row.
         *
         * A missing row is not an error. The API falls back to the base
         * English value, so a partially translated temple is usable rather
         * than blank — which is the state every temple will be in for a long
         * time.
         */
        Schema::create('translations', function (Blueprint $table) {
            $table->id();

            $table->morphs('translatable');

            $table->string('locale', 10);
            $table->string('field', 64);
            $table->text('value')->nullable();

            // Whether a person confirmed this, as opposed to it arriving from
            // a bulk import or machine translation. A wrong deity name in a
            // devotee's own language is worse than no translation, so the API
            // can be told to serve only reviewed text.
            $table->boolean('is_reviewed')->default(false);
            $table->foreignId('reviewed_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(
                ['translatable_type', 'translatable_id', 'locale', 'field'],
                'translations_unique_field',
            );

            // "Everything in Telugu for these temples", the read path.
            $table->index(['translatable_type', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translations');
    }
};
