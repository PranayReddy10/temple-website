<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * A song is a song, whatever it hangs off.
         *
         * devotional_media could only belong to a weekday, so a temple's own
         * suprabhatam and a deity's aarti had nowhere to live but a second
         * table with the same columns and the same licence rules — and a
         * second place to get those rules wrong. The owner becomes
         * polymorphic instead: a DevotionalDay, a Deity or a Temple.
         *
         * The rows are moved rather than recreated. Existing media is already
         * licensed and published; recreating it would reset both.
         */
        Schema::table('devotional_media', function (Blueprint $table) {
            $table->nullableMorphs('mediable');
        });

        DB::table('devotional_media')->whereNotNull('devotional_day_id')->update([
            'mediable_type' => \App\Models\DevotionalDay::class,
            'mediable_id' => DB::raw('devotional_day_id'),
        ]);

        Schema::table('devotional_media', function (Blueprint $table) {
            /*
             * Order matters, and SQLite is the one that says so.
             *
             * MySQL drops an index when its last column goes; SQLite refuses
             * the column drop while an index still names it, so the index and
             * the foreign key both have to go first. Doing it explicitly is
             * correct on both rather than relying on either one's clean-up.
             */
            $table->dropIndex('devotional_media_devotional_day_id_is_published_sort_order_index');
            $table->dropForeign(['devotional_day_id']);
            $table->dropColumn('devotional_day_id');

            // Replaces the index the dropped column carried. The read path is
            // "this owner's published media in order", so all four columns
            // belong in it.
            $table->index(
                ['mediable_type', 'mediable_id', 'is_published', 'sort_order'],
                'devotional_media_owner_published_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('devotional_media', function (Blueprint $table) {
            $table->foreignId('devotional_day_id')->nullable()->constrained()->cascadeOnDelete();
        });

        DB::table('devotional_media')
            ->where('mediable_type', \App\Models\DevotionalDay::class)
            ->update(['devotional_day_id' => DB::raw('mediable_id')]);

        Schema::table('devotional_media', function (Blueprint $table) {
            $table->dropIndex('devotional_media_owner_published_index');
            $table->dropMorphs('mediable');
        });
    }
};
