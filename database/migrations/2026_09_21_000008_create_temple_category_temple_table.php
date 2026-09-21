<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temple_category_temple', function (Blueprint $table) {
            $table->id();
            $table->foreignId('temple_id')->constrained()->cascadeOnDelete();
            $table->foreignId('temple_category_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['temple_id', 'temple_category_id'], 'temple_category_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temple_category_temple');
    }
};
