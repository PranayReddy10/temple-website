<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One account of a temple per devotee, which they edit, rather than one per
 * visit day. Writing again changes it and sends it back for review; it never
 * stacks a second one under the same name on the same temple.
 *
 * Where a devotee already has more than one for a temple, the newest is
 * kept. Safe to run again after a partial failure.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('temple_reviews')) {
            return;
        }

        $duplicates = DB::table('temple_reviews')
            ->select('devotee_id', 'temple_id', DB::raw('max(id) as keep_id'))
            ->groupBy('devotee_id', 'temple_id')
            ->havingRaw('count(*) > 1')
            ->get();

        foreach ($duplicates as $d) {
            DB::table('temple_reviews')
                ->where('devotee_id', $d->devotee_id)
                ->where('temple_id', $d->temple_id)
                ->where('id', '!=', $d->keep_id)
                ->delete();
        }

        Schema::table('temple_reviews', function (Blueprint $table): void {
            // A plain index first, so the foreign key on devotee_id keeps an
            // index to lean on while the old unique one is dropped (MySQL).
            if (! $this->hasIndex('temple_reviews_devotee_id_index')) {
                $table->index('devotee_id');
            }
        });

        Schema::table('temple_reviews', function (Blueprint $table): void {
            if ($this->hasIndex('temple_reviews_devotee_id_temple_id_visited_on_unique')) {
                $table->dropUnique(['devotee_id', 'temple_id', 'visited_on']);
            }
            if (! $this->hasIndex('temple_reviews_devotee_id_temple_id_unique')) {
                $table->unique(['devotee_id', 'temple_id']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('temple_reviews', function (Blueprint $table): void {
            $table->dropUnique(['devotee_id', 'temple_id']);
            $table->unique(['devotee_id', 'temple_id', 'visited_on']);
        });
    }

    private function hasIndex(string $name): bool
    {
        return collect(Schema::getIndexes('temple_reviews'))->contains(fn (array $i): bool => $i['name'] === $name);
    }
};
