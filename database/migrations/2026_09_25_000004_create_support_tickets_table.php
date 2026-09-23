<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Support requests and content reports, in one place.
         *
         * They are the same shape — somebody tells us something is wrong and
         * waits for an answer — and splitting them would mean two queues,
         * two sets of statuses and two chances for a message to sit unread
         * because it arrived in the quieter one. What differs is the `kind`
         * and whether it points at a record.
         *
         * Reports are the load-bearing half. A temple listing with the wrong
         * timings sends devotees to a closed gate, and a photograph of the
         * wrong place attached to a real temple is worse than no photograph;
         * neither is something staff will notice on their own, so the path
         * for telling them has to exist and has to be answered.
         */
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();

            // Quoted back to the person who wrote in, so they can refer to it
            // without an account or a link.
            $table->string('reference', 16)->unique();

            // support | report
            $table->string('kind', 16)->default('support');

            // wrong_information | inappropriate_content | duplicate |
            // account | booking | app_problem | suggestion | other
            $table->string('category', 32)->default('other');

            // new | open | waiting_on_reporter | resolved | closed
            $table->string('status', 24)->default('new');

            // low | normal | high | urgent
            $table->string('priority', 8)->default('normal');

            $table->string('subject');
            $table->text('body');

            /*
             * Who raised it. Any of the three may be the answer, including
             * none of them: a devotee, a staff or temple account, or somebody
             * who is not signed in at all. A report that can only be filed by
             * an account holder is a report most people will not file.
             */
            $table->foreignId('devotee_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reporter_name')->nullable();
            $table->string('reporter_email')->nullable();

            // What it is about: a Temple, a VisitPhoto, a TempleEvent…
            $table->nullableMorphs('about');

            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution_note')->nullable();

            // Where it came from, for working out which screen produces the
            // confusion rather than only that people are confused.
            $table->string('source', 32)->nullable();
            $table->string('app_version', 32)->nullable();
            $table->string('platform', 32)->nullable();

            $table->timestamps();

            // The queue: what is open, oldest first.
            $table->index(['status', 'created_at']);
            $table->index(['kind', 'status']);
            $table->index(['category', 'status']);
        });

        /*
         * The conversation.
         *
         * A support system without a reply thread is a suggestion box: the
         * person who wrote in never hears back, and staff cannot tell what
         * has already been said. Internal notes live here too, flagged, so
         * the working-out and the reply stay in one chronology without the
         * working-out being sent to anybody.
         */
        Schema::create('support_ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained()->cascadeOnDelete();

            // Staff, devotee, or nobody (a system note).
            $table->nullableMorphs('author');

            $table->text('body');

            // An internal note is never shown to the reporter. Defaulting to
            // true would leak the working-out; defaulting to false would send
            // it. It is explicit at every call site instead.
            $table->boolean('is_internal')->default(false);

            $table->timestamps();

            $table->index(['support_ticket_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_messages');
        Schema::dropIfExists('support_tickets');
    }
};
