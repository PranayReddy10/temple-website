<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temple_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('temple_id')->constrained()->cascadeOnDelete();
            // Local and alternate names matter for search: a devotee looking for
            // "Tirupati" should find Sri Venkateswara Swamy Temple.
            $table->string('name');
            // BCP 47 subtag: en, te, hi, ta, kn, ml, mr, bn.
            $table->string('locale', 10)->default('en');
            $table->timestamps();

            $table->index(['temple_id', 'locale']);
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temple_aliases');
    }
};
