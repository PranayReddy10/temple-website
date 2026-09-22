<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devotional_days', function (Blueprint $table) {
            $table->id();

            // 0 = Sunday … 6 = Saturday, matching Carbon's dayOfWeek.
            $table->unsignedTinyInteger('weekday');
            $table->foreignId('deity_id')->constrained()->cascadeOnDelete();

            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->text('significance')->nullable();
            // Shown to devotees on the day, e.g. Om Namah Shivaya.
            $table->string('mantra')->nullable();
            $table->string('mantra_transliteration')->nullable();

            // Drives the app's colour for the day, and tints the admin
            // dashboard. Stored rather than hard-coded because regional
            // traditions differ and this should be editable.
            $table->string('accent_color', 7)->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // More than one deity per day is normal — Saturday is Shani for
            // some and Venkateswara or Hanuman for others — so the weekday
            // alone is not unique; the pair is.
            $table->unique(['weekday', 'deity_id']);
            $table->index(['weekday', 'is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devotional_days');
    }
};
