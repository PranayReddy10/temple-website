<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facilities', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            // amenity | accessibility — accessibility facilities are surfaced
            // separately because they decide whether a visit is possible at all.
            $table->string('group')->default('amenity');
            $table->string('icon')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['group', 'is_active']);
        });

        Schema::create('facility_temple', function (Blueprint $table) {
            $table->id();
            $table->foreignId('temple_id')->constrained()->cascadeOnDelete();
            $table->foreignId('facility_id')->constrained()->cascadeOnDelete();
            // A facility is only listed once an editor has confirmed it. An
            // unverified claim of wheelchair access is worse than no claim.
            $table->boolean('is_verified')->default(false);
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['temple_id', 'facility_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facility_temple');
        Schema::dropIfExists('facilities');
    }
};
