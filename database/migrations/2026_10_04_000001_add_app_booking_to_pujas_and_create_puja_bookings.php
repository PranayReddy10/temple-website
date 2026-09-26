<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Booking a puja, seva or prasadam through the app.
 *
 * Opt-in per item: a temple that lists its sevas only for information keeps
 * the listing it has, and one that wants to take bookings switches it on
 * for the sevas it can actually receive at the counter.
 *
 * Every step checks before it acts, so a deploy that failed half-way through
 * can simply run it again (MySQL does not roll back DDL).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('temple_pujas', function (Blueprint $table): void {
            if (! Schema::hasColumn('temple_pujas', 'kind')) {
                // puja | seva | prasadam
                $table->string('kind', 16)->default('puja')->after('temple_id');
            }
            if (! Schema::hasColumn('temple_pujas', 'app_booking_enabled')) {
                $table->boolean('app_booking_enabled')->default(false)->after('booking_note');
            }
            if (! Schema::hasColumn('temple_pujas', 'fee_per_person')) {
                // ₹100 per person for an archana; ₹1,500 for the abhishekam
                // however many come. Decides what a booking costs.
                $table->boolean('fee_per_person')->default(true)->after('app_booking_enabled');
            }
            if (! Schema::hasColumn('temple_pujas', 'max_people_per_booking')) {
                $table->unsignedSmallInteger('max_people_per_booking')->default(10)->after('fee_per_person');
            }
            if (! Schema::hasColumn('temple_pujas', 'booking_advance_days')) {
                $table->unsignedSmallInteger('booking_advance_days')->default(30)->after('max_people_per_booking');
            }
            if (! Schema::hasColumn('temple_pujas', 'booking_capacity_per_day')) {
                // Null: the temple has not said. Zero is never stored.
                $table->unsignedSmallInteger('booking_capacity_per_day')->nullable()->after('booking_advance_days');
            }
            if (! Schema::hasColumn('temple_pujas', 'booking_instructions')) {
                // Where to report, what to bring, how early to come.
                $table->text('booking_instructions')->nullable()->after('booking_capacity_per_day');
            }
        });

        if (! Schema::hasTable('puja_bookings')) {
            Schema::create('puja_bookings', function (Blueprint $table): void {
                $table->id();
                // Read out at the counter and printed on the receipt.
                $table->string('reference', 16)->unique();
                // What the QR code carries. Random, never the id.
                $table->string('code', 40)->unique();

                $table->foreignId('temple_id')->constrained()->cascadeOnDelete();
                $table->foreignId('temple_puja_id')->constrained('temple_pujas')->cascadeOnDelete();
                $table->foreignId('devotee_id')->constrained()->cascadeOnDelete();
                // Unique: one payment confirms one booking, however many
                // times its webhook is delivered.
                $table->foreignId('payment_id')->nullable()->unique()->constrained()->nullOnDelete();

                $table->date('booked_for');
                $table->unsignedSmallInteger('people')->default(1);

                // Sankalpam details, as the priest will read them.
                $table->string('devotee_name', 120);
                $table->string('devotee_phone', 20)->nullable();
                $table->string('gotram', 80)->nullable();
                $table->string('nakshatram', 80)->nullable();
                $table->string('note', 500)->nullable();

                $table->unsignedInteger('amount_paise')->default(0);
                $table->string('currency', 3)->default('INR');

                // pending_payment | confirmed | verified | cancelled | refunded
                $table->string('status', 24)->default('pending_payment');
                $table->timestamp('confirmed_at')->nullable();
                $table->timestamp('verified_at')->nullable();
                $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('cancelled_at')->nullable();
                // devotee | temple | staff | system
                $table->string('cancelled_by', 16)->nullable();
                $table->string('cancel_reason')->nullable();
                $table->timestamps();

                $table->index(['temple_id', 'booked_for', 'status']);
                $table->index(['temple_puja_id', 'booked_for']);
                $table->index(['devotee_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('puja_bookings');

        Schema::table('temple_pujas', function (Blueprint $table): void {
            $table->dropColumn([
                'kind', 'app_booking_enabled', 'fee_per_person', 'max_people_per_booking',
                'booking_advance_days', 'booking_capacity_per_day', 'booking_instructions',
            ]);
        });
    }
};
