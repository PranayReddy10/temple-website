<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Photo Stamp: the devotee's own photo, and the memory card generated
         * from it.
         *
         * Both are kept. The plan is explicit that the original must survive
         * separately from the stamp, because a devotee who loses the only
         * copy of a photo from a pilgrimage has lost something the product
         * cannot regenerate — the frame, the border and the typography can
         * always be re-rendered, the photograph cannot.
         *
         * Moderation is pending by default. This is user-uploaded imagery
         * attached to named places of worship; it reaches other devotees only
         * after a person has looked at it.
         */
        Schema::create('visit_photos', function (Blueprint $table) {
            $table->id();

            $table->foreignId('devotee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('temple_id')->constrained()->cascadeOnDelete();

            // A photo usually belongs to a check-in, but not always: a photo
            // from an old pilgrimage may arrive before the visit is recorded.
            $table->foreignId('devotee_visit_id')->nullable()
                ->constrained('devotee_visits')->nullOnDelete();

            // Per-row, as everywhere else, so photos survive a later move
            // between storage backends.
            $table->string('disk')->nullable();
            $table->string('original_path');
            $table->string('stamp_path')->nullable();

            $table->string('caption')->nullable();

            // pending | approved | rejected
            $table->string('status', 16)->default('pending');
            $table->foreignId('moderated_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('moderated_at')->nullable();
            $table->text('moderation_note')->nullable();

            // The devotee's own choice, separate from moderation. Both must
            // say yes before anyone else sees it.
            $table->boolean('is_public')->default(false);

            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['devotee_id', 'created_at']);
            $table->index(['temple_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visit_photos');
    }
};
