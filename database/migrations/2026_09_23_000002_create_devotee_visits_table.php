<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * The Passport: a record that a devotee was at a temple.
         *
         * The stamp is not stored. A stamp is "this devotee has a verified
         * visit to this temple", which is a question about the rows here, and
         * storing the answer alongside them creates two places to be wrong.
         *
         * Verification is deliberately a separate axis from the visit itself.
         * A manual check-in is a claim; a GPS or QR check-in is evidence. The
         * product needs both — someone recording a pilgrimage from twenty
         * years ago is the point of a passport — but a collection that treats
         * them identically is a collection nobody can trust.
         */
        Schema::create('devotee_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('devotee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('temple_id')->constrained()->cascadeOnDelete();

            // manual — typed in by the devotee
            // gps    — the device was near the temple at the time
            // qr     — a code at the temple was scanned
            $table->string('method', 16)->default('manual');

            $table->date('visited_on');
            $table->time('visited_at')->nullable();

            // Where the check-in was made, and how far that was from the
            // temple's own coordinates. Kept as recorded rather than
            // recomputed later: the temple's coordinates may be corrected
            // afterwards, and that must not retrospectively invalidate or
            // validate someone's visit.
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedInteger('distance_metres')->nullable();

            $table->text('note')->nullable();

            $table->boolean('is_verified')->default(false);
            $table->timestamp('verified_at')->nullable();

            // A devotee may keep a visit private; only public ones can ever
            // appear on a temple's page or in another devotee's feed.
            $table->boolean('is_public')->default(true);

            $table->timestamps();

            // The passport screen, and the "have I been here?" check.
            $table->index(['devotee_id', 'temple_id']);
            $table->index(['devotee_id', 'visited_on']);
            // "How many visits did this temple get?" for the admin analytics.
            $table->index(['temple_id', 'visited_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devotee_visits');
    }
};
