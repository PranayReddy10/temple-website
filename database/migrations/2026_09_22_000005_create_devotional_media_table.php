<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devotional_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('devotional_day_id')->constrained()->cascadeOnDelete();

            // photo | song | video | chant
            $table->string('type')->default('song');
            $table->string('title');
            $table->text('description')->nullable();

            /*
             * upload  — the file lives on our media disk
             * external — we link to it where it is officially published
             *
             * External is the safer default for recordings: embedding an
             * official upload leaves the rights with whoever holds them.
             */
            $table->string('source_type')->default('external');
            $table->string('disk')->nullable();
            $table->string('path')->nullable();
            $table->string('external_url')->nullable();
            $table->string('thumbnail_path')->nullable();

            /*
             * Rights. A devotional recording belongs to its performer or
             * label even when the composition is centuries old, so these are
             * not optional metadata — they are the record that we may publish
             * it at all. Enforced in the model, not just the form.
             */
            $table->string('artist')->nullable();
            $table->string('credit')->nullable();
            $table->string('license')->nullable();
            $table->string('license_url')->nullable();

            $table->unsignedInteger('duration_seconds')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_published')->default(false);
            $table->timestamps();

            $table->index(['devotional_day_id', 'is_published', 'sort_order']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devotional_media');
    }
};
