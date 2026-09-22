<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            // Nullable because "cleared" and "never set" are different states:
            // a cleared setting should fall back to config, not to an empty
            // string that silently blanks the brand name.
            $table->text('value')->nullable();
            // string | boolean | integer | json — decides how value is cast back.
            $table->string('type')->default('string');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
