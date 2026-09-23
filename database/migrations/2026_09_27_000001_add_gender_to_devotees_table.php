<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gender, optional and self-described.
 *
 * Asked for so that seva bookings and temple rules that differ by gender
 * (dress codes, some sanctums) can be shown to the right person. Nullable:
 * nobody has to answer, and "prefer not to say" is an answer of its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devotees', function (Blueprint $table): void {
            $table->string('gender', 24)->nullable()->after('date_of_birth');
        });
    }

    public function down(): void
    {
        Schema::table('devotees', function (Blueprint $table): void {
            $table->dropColumn('gender');
        });
    }
};
