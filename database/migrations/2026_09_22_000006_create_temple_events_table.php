<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temple_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('temple_id')->constrained()->cascadeOnDelete();

            // festival | program | puja | announcement
            $table->string('type')->default('festival');
            $table->string('title');
            $table->text('description')->nullable();

            $table->string('image_disk')->nullable();
            $table->string('image_path')->nullable();

            $table->date('starts_on');
            // Null means a single-day event, matching temple_closures.
            $table->date('ends_on')->nullable();
            $table->boolean('is_all_day')->default(true);
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();

            // none | yearly — a festival recurs each year, a one-off program
            // does not. Lunar-calendar recurrence is deliberately out of scope:
            // festival dates shift against the Gregorian calendar and guessing
            // them would put wrong dates in front of travelling devotees.
            $table->string('recurrence')->default('none');

            /*
             * draft — being written
             * pending_review — submitted by a temple that may not self-publish
             * published — visible to devotees
             * rejected — staff declined it
             */
            $table->string('status')->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_note')->nullable();

            $table->timestamps();

            $table->index(['temple_id', 'status', 'starts_on']);
            $table->index(['status', 'starts_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temple_events');
    }
};
