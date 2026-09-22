<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('temple_pujas', function (Blueprint $table) {
            // Mirrors temple_photos: the disk is recorded per row so images
            // uploaded before a move to Spaces keep resolving afterwards.
            $table->string('image_disk')->nullable()->after('description');
            $table->string('image_path')->nullable()->after('image_disk');
        });
    }

    public function down(): void
    {
        Schema::table('temple_pujas', function (Blueprint $table) {
            $table->dropColumn(['image_disk', 'image_path']);
        });
    }
};
