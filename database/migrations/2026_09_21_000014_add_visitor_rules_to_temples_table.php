<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('temples', function (Blueprint $table) {
            // Visitor rules from section 4 of the plan. These live on the temple
            // itself rather than in a child table: there is exactly one answer
            // per temple for each, and they are read together as one block.
            $table->text('dress_code')->nullable()->after('built_period');
            $table->string('photography_policy')->nullable()->after('dress_code');
            $table->string('mobile_policy')->nullable()->after('photography_policy');
            $table->string('footwear_policy')->nullable()->after('mobile_policy');
            $table->text('entry_rules')->nullable()->after('footwear_policy');
            $table->text('queue_information')->nullable()->after('entry_rules');
        });
    }

    public function down(): void
    {
        Schema::table('temples', function (Blueprint $table) {
            $table->dropColumn([
                'dress_code', 'photography_policy', 'mobile_policy',
                'footwear_policy', 'entry_rules', 'queue_information',
            ]);
        });
    }
};
