<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temple_timings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('temple_id')->constrained()->cascadeOnDelete();

            // general | darshan | aarti | special
            $table->string('kind')->default('general');
            // Free text for the specific ritual, e.g. Suprabhatam, Mangala Aarti.
            $table->string('label')->nullable();

            // 0 = Sunday … 6 = Saturday. NULL means the timing applies every day,
            // which is the common case; per-day rows are the exception.
            $table->unsignedTinyInteger('day_of_week')->nullable();

            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['temple_id', 'kind', 'day_of_week']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temple_timings');
    }
};
