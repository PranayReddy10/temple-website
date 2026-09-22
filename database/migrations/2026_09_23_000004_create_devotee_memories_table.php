<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * A devotee's own writing about a visit.
         *
         * Private by default, and that default is the feature. A pilgrimage
         * journal is where someone records what they prayed for; publishing
         * it by accident is not a small mistake, so making it public has to
         * be a deliberate act rather than something they failed to turn off.
         *
         * Not merged into devotee_visits.note: a note is a line about the
         * logistics, a memory is a piece of writing with its own title, date
         * and visibility, and one devotee may write several about the same
         * visit over years.
         */
        Schema::create('devotee_memories', function (Blueprint $table) {
            $table->id();

            $table->foreignId('devotee_id')->constrained()->cascadeOnDelete();

            // Both optional: a memory may be about a temple with no recorded
            // visit, or about a pilgrimage as a whole with no single temple.
            $table->foreignId('temple_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('devotee_visit_id')->nullable()
                ->constrained('devotee_visits')->nullOnDelete();

            $table->string('title')->nullable();
            $table->text('body');

            // When it happened, which need not be when it was written.
            $table->date('happened_on')->nullable();

            $table->boolean('is_private')->default(true);

            $table->timestamps();

            $table->index(['devotee_id', 'happened_on']);
            $table->index(['temple_id', 'is_private']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devotee_memories');
    }
};
