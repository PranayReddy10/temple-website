<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The temple trust app: temple teams sign up and register their own temple.
 *
 * A phone on the account, because staff confirm a claim by calling the
 * temple office, and a user_id on a suggestion, so a temple registered from
 * the trust app can be handed back to the account that registered it.
 *
 * Every step checks before it acts, so a deploy that failed half-way through
 * can simply run it again.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'phone')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('phone', 20)->nullable()->after('email');
            });
        }

        if (! Schema::hasColumn('temple_suggestions', 'user_id')) {
            Schema::table('temple_suggestions', function (Blueprint $table): void {
                $table->foreignId('user_id')->nullable()->after('devotee_id')->constrained()->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('temple_suggestions', 'user_id')) {
            Schema::table('temple_suggestions', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('user_id');
            });
        }

        if (Schema::hasColumn('users', 'phone')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('phone');
            });
        }
    }
};
