<?php

use App\Models\Temple;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Wikipedia is no longer used anywhere. Text copied from it may only be
 * shown with its credit (CC BY-SA), so with the credit gone the copied
 * text goes too, with its translations: the description where it was the
 * article's opening, and the history or significance taken from it. A
 * "Wikipedia" source credit is cleared, and the columns are dropped. The
 * temples' Page score (Admin > Temples) then shows what to write anew.
 */
return new class extends Migration
{
    public function up(): void
    {
        $hasSource = Schema::hasColumn('temples', 'description_source');
        $hasFields = Schema::hasColumn('temples', 'wikipedia_fields');
        $morph = (new Temple)->getMorphClass();

        if ($hasSource || $hasFields) {
            DB::table('temples')
                ->where(function ($q) use ($hasSource, $hasFields) {
                    if ($hasSource) {
                        $q->orWhere('description_source', 'wikipedia');
                    }
                    if ($hasFields) {
                        $q->orWhereNotNull('wikipedia_fields');
                    }
                })
                ->orderBy('id')
                ->select(array_values(array_filter(['id', $hasSource ? 'description_source' : null, $hasFields ? 'wikipedia_fields' : null])))
                ->each(function ($row) use ($morph) {
                    $fields = json_decode((string) ($row->wikipedia_fields ?? 'null'), true) ?: [];
                    if (($row->description_source ?? null) === 'wikipedia') {
                        $fields[] = 'short_description';
                    }
                    $fields = array_values(array_intersect(array_unique($fields), ['short_description', 'history', 'significance']));

                    if ($fields === []) {
                        return;
                    }

                    DB::table('temples')->where('id', $row->id)
                        ->update(array_fill_keys($fields, null) + ['updated_at' => now()]);
                    DB::table('translations')
                        ->where('translatable_type', $morph)
                        ->where('translatable_id', $row->id)
                        ->whereIn('field', $fields)
                        ->delete();
                });
        }

        DB::table('temples')
            ->where(fn ($q) => $q->where('source_name', 'Wikipedia')->orWhere('source_url', 'like', '%wikipedia.org%'))
            ->update(['source_name' => null, 'source_url' => null, 'updated_at' => now()]);

        foreach (['wikipedia_url', 'description_source', 'wikipedia_fields'] as $column) {
            if (Schema::hasColumn('temples', $column)) {
                Schema::table('temples', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }

    public function down(): void
    {
        // The copied text is not restored: it may not be shown without its credit.
        Schema::table('temples', function (Blueprint $table) {
            if (! Schema::hasColumn('temples', 'wikipedia_url')) {
                $table->string('wikipedia_url', 500)->nullable();
            }
            if (! Schema::hasColumn('temples', 'description_source')) {
                $table->string('description_source', 20)->nullable();
            }
            if (! Schema::hasColumn('temples', 'wikipedia_fields')) {
                $table->json('wikipedia_fields')->nullable();
            }
        });
    }
};
