<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temple_pujas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('temple_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->text('description')->nullable();
            $table->text('includes')->nullable();
            $table->text('eligibility')->nullable();

            $table->time('starts_at')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            // Free text because sevas are often described as "daily", "Fridays"
            // or "during Brahmotsavam" rather than a fixed clock time.
            $table->string('schedule_note')->nullable();

            // Published fee only. Null means the temple publishes no price,
            // which is different from the puja being free.
            $table->decimal('fee_amount', 10, 2)->nullable();
            $table->string('fee_currency', 3)->default('INR');
            $table->boolean('is_free')->default(false);

            /*
             * Booking route. Section 13 of the plan requires that an unofficial
             * route is never presented as official, so the URL and the claim
             * that it is official are stored as separate, explicit fields.
             */
            $table->string('booking_url')->nullable();
            $table->boolean('booking_is_official')->default(false);
            $table->string('booking_note')->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_published')->default(true);
            $table->timestamps();

            $table->index(['temple_id', 'is_published', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temple_pujas');
    }
};
