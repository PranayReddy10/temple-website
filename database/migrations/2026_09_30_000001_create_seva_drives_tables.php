<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every table checks before it is created, so a deploy that failed half-way
 * through can simply run it again (MySQL does not roll back DDL).
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Seva drives: devotees organising the care of a neglected temple or
         * heritage place — clearing an old shrine, cleaning a temple tank,
         * whitewashing a mandapam — and asking others to join them.
         *
         * A drive is raised by a devotee with photographs of the place as it
         * is, what is wrong, and what they plan to do about it. It reaches
         * nobody else until staff have looked at it: this is an invitation
         * to strangers to meet at a place on a date, and a drive that is not
         * what it claims to be puts people somewhere they should not be.
         *
         * Afterwards the organiser adds the photographs of the place as it is
         * now. Only a drive whose result staff have verified may ask for
         * money, which is what makes "before and after, verified" worth
         * something to a donor.
         */
        if (! Schema::hasTable('seva_drives')) {
            Schema::create('seva_drives', function (Blueprint $table): void {
                $table->id();

                // Who raised it, and who answers for it.
                $table->foreignId('devotee_id')->constrained()->cascadeOnDelete();

                // A listed temple, when the place is one. Many of the places that
                // most need this are not: a roadside shrine, a stepwell, a ruin.
                $table->foreignId('temple_id')->nullable()->constrained()->nullOnDelete();

                $table->string('title', 120);

                // cleaning | water_body | restoration | painting | lighting |
                // plantation | documentation | other
                $table->string('cause', 24)->default('cleaning');

                // Where, in words a volunteer can find it by.
                $table->string('place_name');
                $table->string('address')->nullable();
                $table->string('city', 80)->nullable();
                $table->foreignId('state_id')->nullable()->constrained()->nullOnDelete();
                $table->decimal('latitude', 10, 7)->nullable();
                $table->decimal('longitude', 10, 7)->nullable();
                $table->string('meeting_point')->nullable();

                // What is wrong now, and what will be done about it.
                $table->text('problem');
                $table->text('plan');
                $table->text('what_to_bring')->nullable();

                $table->dateTime('starts_at');
                $table->dateTime('ends_at')->nullable();
                $table->unsignedSmallInteger('volunteers_needed')->nullable();

                // Shown only to the organiser and to people who have joined.
                $table->string('contact_phone', 20)->nullable();

                // pending | approved | rejected | completed | verified | cancelled
                $table->string('status', 16)->default('pending');
                $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('moderated_at')->nullable();
                $table->text('moderation_note')->nullable();

                // The organiser's account of what was done.
                $table->timestamp('completed_at')->nullable();
                $table->text('completion_note')->nullable();

                // Staff saying the before and after photographs are real.
                $table->timestamp('verified_at')->nullable();
                $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();

                /*
                 * Donations go straight to the organiser's UPI ID; no money passes
                 * through the platform. What the platform adds is the gate: the
                 * UPI ID is served only once the work has been verified, and staff
                 * can switch it off again without touching anything else.
                 */
                $table->string('upi_id', 64)->nullable();
                $table->string('upi_name', 80)->nullable();
                $table->unsignedInteger('donation_goal')->nullable();
                $table->string('donation_purpose')->nullable();
                $table->boolean('donations_enabled')->default(true);

                $table->timestamps();

                // The public listing: what is coming up, soonest first.
                $table->index(['status', 'starts_at']);
                $table->index(['devotee_id', 'created_at']);
                $table->index(['temple_id', 'status']);
            });
        }

        /*
         * Photographs and videos, before and after.
         *
         * A video may be an uploaded file or a link to where it is already
         * published; linking costs no storage and is usually the better
         * choice on shared hosting.
         */
        if (! Schema::hasTable('seva_drive_media')) {
            Schema::create('seva_drive_media', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('seva_drive_id')->constrained()->cascadeOnDelete();
                $table->foreignId('devotee_id')->nullable()->constrained()->nullOnDelete();

                // before | after
                $table->string('stage', 8)->default('before');

                // photo | video
                $table->string('type', 8)->default('photo');

                $table->string('disk')->nullable();
                $table->string('path')->nullable();
                $table->string('video_url')->nullable();
                $table->string('caption')->nullable();

                // Staff can take one image down without rejecting the drive.
                $table->boolean('is_hidden')->default(false);

                $table->timestamps();

                $table->index(['seva_drive_id', 'stage']);
            });
        }

        if (! Schema::hasTable('seva_drive_volunteers')) {
            Schema::create('seva_drive_volunteers', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('seva_drive_id')->constrained()->cascadeOnDelete();
                $table->foreignId('devotee_id')->constrained()->cascadeOnDelete();

                // How many are coming on this one sign-up: a family is one row.
                $table->unsignedTinyInteger('party_size')->default(1);
                $table->string('note')->nullable();

                // Marked by the organiser afterwards. Null: not yet said.
                $table->boolean('attended')->nullable();

                $table->timestamps();

                $table->unique(['seva_drive_id', 'devotee_id']);
            });
        }

        /*
         * What donors say they sent.
         *
         * Self-reported, because the money goes to the organiser directly and
         * the platform never sees it. The organiser confirms each one they
         * received, and only confirmed amounts count towards the goal shown
         * to everybody else.
         */
        if (! Schema::hasTable('seva_drive_donations')) {
            Schema::create('seva_drive_donations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('seva_drive_id')->constrained()->cascadeOnDelete();
                $table->foreignId('devotee_id')->nullable()->constrained()->nullOnDelete();
                $table->unsignedInteger('amount');
                $table->string('upi_ref', 40)->nullable();
                $table->string('message')->nullable();
                $table->boolean('is_anonymous')->default(false);
                $table->timestamp('confirmed_at')->nullable();
                $table->timestamps();

                $table->index(['seva_drive_id', 'confirmed_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('seva_drive_donations');
        Schema::dropIfExists('seva_drive_volunteers');
        Schema::dropIfExists('seva_drive_media');
        Schema::dropIfExists('seva_drives');
    }
};
