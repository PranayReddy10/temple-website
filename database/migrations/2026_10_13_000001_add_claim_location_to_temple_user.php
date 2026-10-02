<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Where the person stood when they asked to manage a temple.
 *
 * Asking from the trust app needs a live GPS fix at the temple, so staff can
 * see the request came from someone who was really there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('temple_user', function (Blueprint $table) {
            $table->decimal('claim_latitude', 10, 7)->nullable()->after('claim_note');
            $table->decimal('claim_longitude', 10, 7)->nullable()->after('claim_latitude');
            $table->unsignedInteger('claim_accuracy_m')->nullable()->after('claim_longitude');
            $table->unsignedInteger('claim_distance_m')->nullable()->after('claim_accuracy_m');
        });
    }

    public function down(): void
    {
        Schema::table('temple_user', function (Blueprint $table) {
            $table->dropColumn(['claim_latitude', 'claim_longitude', 'claim_accuracy_m', 'claim_distance_m']);
        });
    }
};
