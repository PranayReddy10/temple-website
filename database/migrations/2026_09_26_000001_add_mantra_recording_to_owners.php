<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Everything that carries a mantra and can therefore carry its recording. */
    protected const TABLES = ['deities', 'temples', 'devotional_days'];

    public function up(): void
    {
        /*
         * A mantra can now be heard, not only read.
         *
         * A pointer at a devotional_media row rather than a set of audio
         * columns on each of these three tables. That table already holds
         * "a link to where it is published, or a file we host", already
         * carries the artist, credit, licence and duration, and already
         * refuses to publish a recording with no stated rights. Four audio
         * columns on three tables would be twelve columns reimplementing all
         * of it, in three places, with three chances to get the rights rule
         * wrong.
         *
         * nullOnDelete, not cascade: deleting a recording must not delete the
         * deity. The mantra text stays and the audio simply goes quiet, which
         * is the state the app already handles for a mantra that never had a
         * recording in the first place.
         */
        foreach (self::TABLES as $table) {
            if (Schema::hasColumn($table, 'mantra_media_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->foreignId('mantra_media_id')
                    ->nullable()
                    ->after('mantra_transliteration')
                    ->constrained('devotional_media')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasColumn($table, 'mantra_media_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropForeign(['mantra_media_id']);
                $blueprint->dropColumn('mantra_media_id');
            });
        }
    }
};
