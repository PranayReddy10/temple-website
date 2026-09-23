<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected const TABLE = 'devotional_media';

    protected const OLD_COLUMN = 'devotional_day_id';

    protected const OLD_INDEX = 'devotional_media_devotional_day_id_is_published_sort_order_index';

    protected const NEW_INDEX = 'devotional_media_owner_published_index';

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
         *
         * Every step is guarded, because this migration has already failed
         * half-way on a real database. MySQL does not roll back DDL, so a
         * migration that dies part-way leaves the table changed and the
         * migration unrecorded — and the retry then falls over on the work
         * that did land. Each step checks whether it is still needed.
         */
        $this->addMorphColumns();
        $this->backfillOwners();
        $this->dropOldColumn();
        $this->addOwnerIndex();
    }

    protected function addMorphColumns(): void
    {
        if (Schema::hasColumn(self::TABLE, 'mediable_type')) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->nullableMorphs('mediable');
        });
    }

    /** Only rows that have not been moved yet, so a retry cannot double-write. */
    protected function backfillOwners(): void
    {
        if (! Schema::hasColumn(self::TABLE, self::OLD_COLUMN)) {
            return;
        }

        DB::table(self::TABLE)
            ->whereNotNull(self::OLD_COLUMN)
            ->whereNull('mediable_id')
            ->update([
                'mediable_type' => \App\Models\DevotionalDay::class,
                'mediable_id' => DB::raw(self::OLD_COLUMN),
            ]);
    }

    protected function dropOldColumn(): void
    {
        if (! Schema::hasColumn(self::TABLE, self::OLD_COLUMN)) {
            return;
        }

        /*
         * Order matters, and the two engines disagree about which order.
         *
         * MySQL refuses to drop the index while the foreign key still needs
         * it — an InnoDB foreign key requires an index on its column, and
         * this composite one was serving as it. SQLite refuses to drop the
         * column while any index still names it.
         *
         * Foreign key, then index, then column satisfies both. The reverse —
         * which is what shipped, because SQLite was the only engine the tests
         * ran on — dies on MySQL with error 1553 after the columns have
         * already been added.
         */
        if ($this->hasForeignKey()) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->dropForeign([self::OLD_COLUMN]);
            });
        }

        if ($this->hasIndex(self::OLD_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->dropIndex(self::OLD_INDEX);
            });
        }

        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->dropColumn(self::OLD_COLUMN);
        });
    }

    /**
     * Replaces the index the dropped column carried. The read path is "this
     * owner's published media in order", so all four columns belong in it.
     */
    protected function addOwnerIndex(): void
    {
        if ($this->hasIndex(self::NEW_INDEX)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->index(
                ['mediable_type', 'mediable_id', 'is_published', 'sort_order'],
                self::NEW_INDEX,
            );
        });
    }

    protected function hasIndex(string $name): bool
    {
        return collect(Schema::getIndexes(self::TABLE))
            ->contains(fn (array $index): bool => $index['name'] === $name);
    }

    /**
     * SQLite reports no foreign keys by name the way MySQL does, and has
     * nothing to drop here in practice, so an absent one is not an error.
     */
    protected function hasForeignKey(): bool
    {
        return collect(Schema::getForeignKeys(self::TABLE))
            ->contains(fn (array $key): bool => in_array(self::OLD_COLUMN, $key['columns'], true));
    }

    public function down(): void
    {
        if (! Schema::hasColumn(self::TABLE, self::OLD_COLUMN)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->foreignId(self::OLD_COLUMN)->nullable()->constrained()->cascadeOnDelete();
            });
        }

        DB::table(self::TABLE)
            ->where('mediable_type', \App\Models\DevotionalDay::class)
            ->update([self::OLD_COLUMN => DB::raw('mediable_id')]);

        if ($this->hasIndex(self::NEW_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->dropIndex(self::NEW_INDEX);
            });
        }

        if (Schema::hasColumn(self::TABLE, 'mediable_type')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->dropMorphs('mediable');
            });
        }
    }
};
