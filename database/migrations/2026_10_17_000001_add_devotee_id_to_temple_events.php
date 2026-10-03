<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A devotee may raise a bhajan gathering at a temple from the app, the way
 * they raise a seva drive. Such an event remembers who raised it; a temple
 * team's own events keep created_by (a user) and leave this null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('temple_events', function (Blueprint $table): void {
            $table->foreignId('devotee_id')->nullable()->after('created_by')->constrained('devotees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('temple_events', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('devotee_id');
        });
    }
};
