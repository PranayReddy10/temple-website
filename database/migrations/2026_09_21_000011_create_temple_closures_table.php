<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temple_closures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('temple_id')->constrained()->cascadeOnDelete();

            $table->date('starts_on');
            // NULL means a single-day closure; kept separate from starts_on so
            // a one-day entry does not require typing the same date twice.
            $table->date('ends_on')->nullable();

            $table->string('reason');
            // A temple may close entirely, or only alter its hours. Devotees
            // travelling a long way need that distinction.
            $table->boolean('is_full_day')->default(true);
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['temple_id', 'starts_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temple_closures');
    }
};
