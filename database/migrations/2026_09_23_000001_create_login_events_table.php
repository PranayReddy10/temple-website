<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Every sign-in attempt, staff and devotee alike.
         *
         * A `last_login_at` column answers "when did they last sign in" and
         * nothing else. It cannot say how many people signed in this week,
         * whether a returning devotee is a daily user or a once-a-year one,
         * or that one account has failed twenty attempts from three
         * countries. Those are the questions the analytics screen exists to
         * answer, and none of them can be reconstructed after the fact from a
         * column that only remembers the most recent value.
         *
         * Polymorphic because both guards sign in: a staff User in the panels
         * and a Devotee through the API. One table keeps "sign-ins today"
         * a single query rather than a union that someone will forget to
         * update when a third guard arrives.
         */
        Schema::create('login_events', function (Blueprint $table) {
            $table->id();

            // Nullable: a failed attempt against an unknown identifier has no
            // account to point at, and those attempts are exactly the ones
            // worth counting.
            $table->nullableMorphs('authenticatable');

            $table->string('guard', 32);

            // What was typed, for failures only. Never a password.
            $table->string('identifier')->nullable();

            $table->boolean('succeeded')->default(true);
            $table->string('failure_reason', 64)->nullable();

            // 45 chars holds an IPv6 address with an IPv4 tail.
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            // Reported by the app, not inferred from the user agent.
            $table->string('platform', 32)->nullable();
            $table->string('app_version', 32)->nullable();

            $table->timestamp('occurred_at')->index();

            // No updated_at: an event is a fact, it does not change.
            $table->timestamp('created_at')->nullable();

            $table->index(['guard', 'occurred_at']);
            $table->index(['succeeded', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_events');
    }
};
