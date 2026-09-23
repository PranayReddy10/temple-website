<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('temples', function (Blueprint $table) {
            /*
             * A temple's own mantra, where it has one.
             *
             * Not the same as the deity's. Tirumala's Suprabhatam is sung at
             * Tirumala; a devotee opening the app there should get that, not
             * the generic Vishnu mantra — and a temple without its own falls
             * back to its deity's rather than showing nothing.
             */
            $table->text('mantra')->nullable()->after('significance');
            $table->text('mantra_transliteration')->nullable()->after('mantra');
        });
    }

    public function down(): void
    {
        Schema::table('temples', function (Blueprint $table) {
            $table->dropColumn(['mantra', 'mantra_transliteration']);
        });
    }
};
