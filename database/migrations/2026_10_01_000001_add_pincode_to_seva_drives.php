<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A PIN code and district on each seva drive: typing the PIN code fills in
 * the state, district and town, and volunteers search by what they know.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seva_drives', function (Blueprint $table): void {
            if (! Schema::hasColumn('seva_drives', 'pincode')) {
                $table->string('pincode', 6)->nullable()->after('address');
            }
            if (! Schema::hasColumn('seva_drives', 'district')) {
                $table->string('district', 80)->nullable()->after('city');
            }
        });
    }

    public function down(): void
    {
        Schema::table('seva_drives', function (Blueprint $table): void {
            $table->dropColumn(['pincode', 'district']);
        });
    }
};
