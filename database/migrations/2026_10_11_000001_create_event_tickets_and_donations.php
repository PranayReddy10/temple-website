<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bhajan gatherings, events devotees join or buy tickets for, and online
 * hundi donations, all settled with the temple like seva bookings.
 *
 * - temple_events gains what a gathering needs: who leads it, whether
 *   anyone may come, "I'll join", a ticket price and a headcount limit, a
 *   song list, and weekly repetition.
 * - event_registrations: one devotee's place (or paid tickets) at one date
 *   of an event, with a code the counter scans, as a seva booking has.
 * - temple_donations: money given to a temple's hundi through the app.
 * - temple_settlements gains the split between the three.
 *
 * Every step checks before it acts, so a deploy that failed half-way through
 * can simply run it again (MySQL does not roll back DDL).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('temple_events', function (Blueprint $table): void {
            if (! Schema::hasColumn('temple_events', 'group_name')) {
                // The bhajan mandali, the organiser, the speaker.
                $table->string('group_name', 160)->nullable()->after('description');
            }
            if (! Schema::hasColumn('temple_events', 'open_to_all')) {
                $table->boolean('open_to_all')->default(true)->after('group_name');
            }
            if (! Schema::hasColumn('temple_events', 'registration_enabled')) {
                // "I'll join" for a free gathering; tickets for a paid one.
                $table->boolean('registration_enabled')->default(false)->after('open_to_all');
            }
            if (! Schema::hasColumn('temple_events', 'ticket_price_paise')) {
                // Per person. Zero: free to join.
                $table->unsignedInteger('ticket_price_paise')->default(0)->after('registration_enabled');
            }
            if (! Schema::hasColumn('temple_events', 'capacity')) {
                // People per date; null for no limit.
                $table->unsignedInteger('capacity')->nullable()->after('ticket_price_paise');
            }
            if (! Schema::hasColumn('temple_events', 'max_people_per_registration')) {
                $table->unsignedSmallInteger('max_people_per_registration')->default(10)->after('capacity');
            }
            if (! Schema::hasColumn('temple_events', 'songs')) {
                // One per line: what will be sung, for devotees to follow.
                $table->text('songs')->nullable()->after('max_people_per_registration');
            }
        });

        if (! Schema::hasTable('event_registrations')) {
            Schema::create('event_registrations', function (Blueprint $table): void {
                $table->id();
                $table->string('reference', 16)->unique();
                $table->string('code', 40)->unique();
                $table->foreignId('temple_event_id')->constrained()->cascadeOnDelete();
                $table->foreignId('temple_id')->constrained()->cascadeOnDelete();
                $table->foreignId('devotee_id')->constrained()->cascadeOnDelete();
                $table->foreignId('payment_id')->nullable()->unique()->constrained()->nullOnDelete();
                // Which date of a weekly gathering; the date itself otherwise.
                $table->date('occurs_on');
                $table->unsignedSmallInteger('people')->default(1);
                $table->string('devotee_name', 120);
                $table->string('devotee_phone', 20)->nullable();
                $table->unsignedInteger('amount_paise')->default(0);
                $table->string('currency', 3)->default('INR');
                // The seva booking statuses: pending_payment | confirmed |
                // verified | cancelled | refunded | expired
                $table->string('status', 24)->default('pending_payment');
                $table->timestamp('confirmed_at')->nullable();
                $table->timestamp('verified_at')->nullable();
                $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('expired_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->string('cancelled_by', 16)->nullable();
                $table->string('cancel_reason')->nullable();
                $table->foreignId('settlement_id')->nullable()->constrained('temple_settlements')->nullOnDelete();
                $table->timestamps();

                $table->index(['temple_event_id', 'occurs_on', 'status']);
                $table->index(['temple_id', 'occurs_on', 'status']);
                $table->index(['devotee_id', 'status']);
            });
        }

        if (! Schema::hasTable('temple_donations')) {
            Schema::create('temple_donations', function (Blueprint $table): void {
                $table->id();
                $table->string('reference', 16)->unique();
                $table->foreignId('temple_id')->constrained()->cascadeOnDelete();
                $table->foreignId('devotee_id')->constrained()->cascadeOnDelete();
                $table->foreignId('payment_id')->nullable()->unique()->constrained()->nullOnDelete();
                $table->unsignedInteger('amount_paise');
                $table->string('currency', 3)->default('INR');
                // general | annadanam | maintenance | gau_seva | festival | other
                $table->string('purpose', 24)->default('general');
                $table->string('donor_name', 120)->nullable();
                // The temple sees "A devotee" rather than the name.
                $table->boolean('is_anonymous')->default(false);
                $table->string('note', 300)->nullable();
                // pending_payment | paid | failed | refunded
                $table->string('status', 24)->default('pending_payment');
                $table->timestamp('paid_at')->nullable();
                // The day it counts for, in the temple's time zone.
                $table->date('paid_on')->nullable();
                $table->foreignId('settlement_id')->nullable()->constrained('temple_settlements')->nullOnDelete();
                $table->timestamps();

                $table->index(['temple_id', 'status', 'paid_on']);
                $table->index(['devotee_id', 'status']);
            });
        }

        Schema::table('temples', function (Blueprint $table): void {
            if (! Schema::hasColumn('temples', 'accepts_donations')) {
                // The temple's owner switches the online hundi on.
                $table->boolean('accepts_donations')->default(false)->after('is_featured');
            }
        });

        Schema::table('temple_settlements', function (Blueprint $table): void {
            if (! Schema::hasColumn('temple_settlements', 'bookings_paise')) {
                $table->unsignedBigInteger('bookings_paise')->default(0)->after('bookings_count');
            }
            if (! Schema::hasColumn('temple_settlements', 'tickets_count')) {
                $table->unsignedInteger('tickets_count')->default(0)->after('bookings_paise');
            }
            if (! Schema::hasColumn('temple_settlements', 'tickets_paise')) {
                $table->unsignedBigInteger('tickets_paise')->default(0)->after('tickets_count');
            }
            if (! Schema::hasColumn('temple_settlements', 'donations_count')) {
                $table->unsignedInteger('donations_count')->default(0)->after('tickets_paise');
            }
            if (! Schema::hasColumn('temple_settlements', 'donations_paise')) {
                $table->unsignedBigInteger('donations_paise')->default(0)->after('donations_count');
            }
            if (! Schema::hasColumn('temple_settlements', 'donation_fee_percent')) {
                $table->decimal('donation_fee_percent', 5, 2)->default(0)->after('fee_percent');
            }
        });
    }

    public function down(): void
    {
        Schema::table('temple_settlements', function (Blueprint $table): void {
            $table->dropColumn(['bookings_paise', 'tickets_count', 'tickets_paise', 'donations_count', 'donations_paise', 'donation_fee_percent']);
        });
        Schema::table('temples', function (Blueprint $table): void {
            $table->dropColumn('accepts_donations');
        });
        Schema::dropIfExists('temple_donations');
        Schema::dropIfExists('event_registrations');
        Schema::table('temple_events', function (Blueprint $table): void {
            $table->dropColumn(['group_name', 'open_to_all', 'registration_enabled', 'ticket_price_paise', 'capacity', 'max_people_per_registration', 'songs']);
        });
    }
};
