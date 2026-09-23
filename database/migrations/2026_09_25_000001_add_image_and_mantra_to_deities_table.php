<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deities', function (Blueprint $table) {
            // Per-row disk, as everywhere else, so an image uploaded before a
            // move to Spaces keeps resolving afterwards.
            $table->string('image_disk')->nullable()->after('description');
            $table->string('image_path')->nullable()->after('image_disk');
            $table->string('image_credit')->nullable()->after('image_path');

            /*
             * The mantra belongs to the deity, not to the weekday.
             *
             * It lived only on devotional_days, which meant the same Shiva
             * mantra had to be typed again on every day and every screen that
             * wanted it — and corrected in every one of them when it was
             * wrong. A day may still override it where a tradition differs;
             * it now falls back here instead of being blank.
             */
            $table->text('mantra')->nullable()->after('image_credit');
            $table->text('mantra_transliteration')->nullable()->after('mantra');
            $table->text('mantra_meaning')->nullable()->after('mantra_transliteration');

            // Hex, used to tint the app and the admin on this deity's day.
            $table->string('accent_color', 7)->nullable()->after('mantra_meaning');
        });
    }

    public function down(): void
    {
        Schema::table('deities', function (Blueprint $table) {
            $table->dropColumn([
                'image_disk', 'image_path', 'image_credit',
                'mantra', 'mantra_transliteration', 'mantra_meaning', 'accent_color',
            ]);
        });
    }
};
