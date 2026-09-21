<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temples', function (Blueprint $table) {
            $table->id();

            // --- Identity ---
            $table->string('name');
            $table->string('slug')->unique();
            $table->foreignId('deity_id')->nullable()->constrained()->nullOnDelete();

            // --- Location ---
            $table->foreignId('state_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('district_id')->nullable()->constrained()->nullOnDelete();
            $table->string('city')->nullable();
            $table->text('address')->nullable();
            $table->string('pincode', 10)->nullable();
            // 7 decimal places resolves to roughly 1cm, far beyond what temple
            // coordinates need, but costs nothing and avoids rounding surprises.
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            // --- Content ---
            $table->text('short_description')->nullable();
            $table->longText('history')->nullable();
            $table->longText('significance')->nullable();
            $table->string('architecture_style')->nullable();
            // Free text because many temples are dated only by century or era.
            $table->string('built_period')->nullable();

            // --- Contact ---
            $table->string('official_website')->nullable();
            $table->string('contact_phone', 40)->nullable();
            $table->string('contact_email')->nullable();

            // --- Trust and provenance (project plan, section 20) ---
            // Never blur official, verified, community and sponsored content.
            $table->string('verification_status')->default('unverified');
            $table->string('source_name')->nullable();
            $table->string('source_url')->nullable();
            $table->date('last_verified_at')->nullable();

            // --- Editorial workflow ---
            $table->string('status')->default('draft'); // draft | in_review | published | archived
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // Drives the admin list view and, later, the public API filters.
            $table->index(['status', 'state_id']);
            $table->index(['status', 'deity_id']);
            // Bounding-box prefilter before the expensive distance calculation
            // in "temples near me".
            $table->index(['latitude', 'longitude']);
            $table->index('verification_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temples');
    }
};
