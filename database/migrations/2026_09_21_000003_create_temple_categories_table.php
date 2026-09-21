<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temple_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            // 'circuit' groups such as Jyotirlinga or Char Dham have a fixed,
            // well-known member count; 'type' groups such as Cave Temple do not.
            $table->string('kind')->default('circuit');
            $table->text('description')->nullable();
            // Expected number of temples in a recognised circuit, so the admin
            // can show completeness (e.g. 9 of 12 Jyotirlingas recorded).
            $table->unsignedSmallInteger('expected_count')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['kind', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temple_categories');
    }
};
