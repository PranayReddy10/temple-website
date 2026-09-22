<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * A planned pilgrimage: several temples, over some days, in an order.
         *
         * Status is what makes "how many devotees are planning a trip"
         * answerable, and it is the reason this is not just a list of saved
         * temples. A saved temple is an intention; a yatra with dates and
         * stops is a plan, and the difference matters to a temple deciding
         * whether to expect a crowd.
         */
        Schema::create('yatras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('devotee_id')->constrained()->cascadeOnDelete();

            $table->string('title');
            $table->text('description')->nullable();

            // planning | confirmed | in_progress | completed | abandoned
            $table->string('status', 16)->default('planning');

            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();

            $table->unsignedSmallInteger('party_size')->nullable();

            // A shared itinerary others can copy. Off by default, like every
            // other devotee-owned record here.
            $table->boolean('is_public')->default(false);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['devotee_id', 'status']);
            // "How many trips are being planned, and for when?"
            $table->index(['status', 'starts_on']);
        });

        Schema::create('yatra_stops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('yatra_id')->constrained()->cascadeOnDelete();
            $table->foreignId('temple_id')->constrained()->cascadeOnDelete();

            // Day 1, day 2… within the trip, and the order within that day.
            $table->unsignedSmallInteger('day_number')->default(1);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->date('planned_on')->nullable();
            $table->text('note')->nullable();

            // Closing the loop with the Passport: a stop is done when the
            // visit it produced is recorded.
            $table->foreignId('devotee_visit_id')->nullable()
                ->constrained('devotee_visits')->nullOnDelete();

            $table->timestamps();

            // One temple cannot appear twice in the same trip; a devotee who
            // really means to return twice wants two trips, or one stop with
            // a note.
            $table->unique(['yatra_id', 'temple_id']);
            $table->index(['yatra_id', 'day_number', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('yatra_stops');
        Schema::dropIfExists('yatras');
    }
};
