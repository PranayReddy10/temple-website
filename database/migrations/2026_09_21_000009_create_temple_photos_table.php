<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temple_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('temple_id')->constrained()->cascadeOnDelete();

            // Which filesystem disk holds this file. Stored per row so photos
            // uploaded before a move to DigitalOcean Spaces keep resolving.
            $table->string('disk')->default('public');
            $table->string('path');
            $table->string('medium_path')->nullable();
            $table->string('thumbnail_path')->nullable();

            $table->string('category')->default('gallery');
            $table->string('caption')->nullable();

            // Attribution is not optional for photographs we did not take.
            $table->string('credit')->nullable();
            $table->string('source_url')->nullable();
            $table->string('license')->nullable();

            $table->boolean('is_primary')->default(false);
            $table->boolean('is_published')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('mime_type', 100)->nullable();

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['temple_id', 'is_published', 'sort_order']);
            $table->index(['temple_id', 'is_primary']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temple_photos');
    }
};
