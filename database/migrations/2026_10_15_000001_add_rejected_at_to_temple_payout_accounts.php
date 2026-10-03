<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* When staff refused the verification, shown to the owner with the reason. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('temple_payout_accounts', function (Blueprint $table) {
            $table->timestamp('rejected_at')->nullable()->after('rejection_reason');
        });
    }

    public function down(): void
    {
        Schema::table('temple_payout_accounts', function (Blueprint $table) {
            $table->dropColumn('rejected_at');
        });
    }
};
