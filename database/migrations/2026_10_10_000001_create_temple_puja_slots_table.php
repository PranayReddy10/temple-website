<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Time slots for a seva, like show times: "09:00–10:00, 15 people". A
 * booking takes seats in one slot on one day; the slot's times are copied
 * onto the booking so a later change to the slot never rewrites a ticket.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temple_puja_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('temple_puja_id')->constrained('temple_pujas')->cascadeOnDelete();
            $table->time('starts_at');
            $table->time('ends_at')->nullable();
            // People per day in this slot; null is no limit.
            $table->unsignedInteger('capacity')->nullable();
            // 0 (Sunday) … 6 (Saturday); null is every day.
            $table->json('days')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['temple_puja_id', 'is_active']);
        });

        Schema::table('puja_bookings', function (Blueprint $table) {
            $table->foreignId('temple_puja_slot_id')->nullable()->after('temple_puja_id')
                ->constrained('temple_puja_slots')->nullOnDelete();
            $table->time('slot_starts_at')->nullable()->after('booked_for');
            $table->time('slot_ends_at')->nullable()->after('slot_starts_at');
            $table->timestamp('expired_at')->nullable()->after('verified_by');

            $table->index(['temple_puja_slot_id', 'booked_for']);
        });
    }

    public function down(): void
    {
        Schema::table('puja_bookings', function (Blueprint $table) {
            $table->dropIndex(['temple_puja_slot_id', 'booked_for']);
            $table->dropConstrainedForeignId('temple_puja_slot_id');
            $table->dropColumn(['slot_starts_at', 'slot_ends_at', 'expired_at']);
        });
        Schema::dropIfExists('temple_puja_slots');
    }
};
