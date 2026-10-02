<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * India's Hindu festivals and vrat days, for every devotee's calendar,
 * independent of any one temple's events.
 *
 * Loaded from database/data/festivals.json (computed by
 * tool/panchang/festivals.py) and correctable in the admin: `computed_on`
 * keeps the date as computed, so a later import recognises a row an editor
 * moved and does not add it again.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('festivals')) {
            return;
        }

        Schema::create('festivals', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 80);
            $table->string('name', 160);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            // festival | vrat (Ekadashi, Purnima, Amavasya, Sankashti, Pradosh...)
            $table->string('kind', 16)->default('festival');
            $table->boolean('is_major')->default(false);
            // A deity slug, to tint the app's card in the day's colours.
            $table->string('deity', 40)->nullable();
            $table->text('description')->nullable();
            // "Chaitra Shukla Navami", as almanacs name the day.
            $table->string('tithi', 80)->nullable();
            $table->boolean('is_published')->default(true);
            $table->date('computed_on')->nullable();
            $table->timestamps();

            $table->unique(['slug', 'computed_on']);
            $table->index(['starts_on', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('festivals');
    }
};
