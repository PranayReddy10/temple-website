<?php

namespace Tests\Feature;

use App\Models\Deity;
use App\Models\DevotionalDay;
use App\Models\DevotionalMedia;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The devotional media migration has to survive being run twice.
 *
 * It already failed half-way through a real deploy: the morph columns were
 * added, the backfill ran, and then dropping the old index was refused
 * because a foreign key still needed it. MySQL does not roll back DDL, so
 * the table stayed altered while the migration went unrecorded — and the
 * obvious retry then died on "duplicate column mediable_type", leaving the
 * site stuck with no way forward that did not involve hand-editing the
 * schema on the server.
 *
 * A migration that can fail part-way has to be safe to run again. These
 * tests put the table into the state that deploy left it in and check that
 * running the migration finishes the job.
 */
class MigrationResumabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function migration(): object
    {
        return require database_path('migrations/2026_09_25_000002_make_devotional_media_polymorphic.php');
    }

    public function test_running_it_again_on_a_finished_table_changes_nothing(): void
    {
        // RefreshDatabase has already run it once.
        $this->migration()->up();

        $this->assertTrue(Schema::hasColumn('devotional_media', 'mediable_type'));
        $this->assertFalse(Schema::hasColumn('devotional_media', 'devotional_day_id'));
    }

    /** Existing media keeps its owner, its licence and its published state. */
    public function test_it_does_not_disturb_media_that_has_already_moved(): void
    {
        $deity = Deity::create(['name' => 'Shiva', 'slug' => 'shiva']);
        $day = DevotionalDay::create(['weekday' => 1, 'deity_id' => $deity->id, 'title' => 'Somavara']);

        $media = $day->media()->create([
            'type' => \App\Enums\DevotionalMediaType::Song,
            'title' => 'A bhajan',
            'external_url' => 'https://example.com/a',
            'license' => 'Licensed from the label',
            'is_published' => true,
        ]);

        $this->migration()->up();

        $media->refresh();

        $this->assertTrue($media->mediable->is($day));
        $this->assertTrue($media->is_published);
        $this->assertSame('Licensed from the label', $media->license);
        $this->assertSame(1, DevotionalMedia::count());
    }

    /**
     * The state the failed deploy actually left behind: morph columns
     * present, old column still there, nothing recorded.
     */
    public function test_it_finishes_the_job_from_a_half_applied_table(): void
    {
        $deity = Deity::create(['name' => 'Shiva', 'slug' => 'shiva']);
        $day = DevotionalDay::create(['weekday' => 1, 'deity_id' => $deity->id, 'title' => 'Somavara']);

        $this->rewindToHalfApplied($day->id);

        $this->assertTrue(Schema::hasColumn('devotional_media', 'devotional_day_id'));
        $this->assertTrue(Schema::hasColumn('devotional_media', 'mediable_type'));

        $this->migration()->up();

        $this->assertFalse(Schema::hasColumn('devotional_media', 'devotional_day_id'));

        // And the row that was mid-flight ends up owned, not orphaned.
        $media = DevotionalMedia::firstOrFail();
        $this->assertSame(DevotionalDay::class, $media->mediable_type);
        $this->assertSame($day->id, (int) $media->mediable_id);
    }

    /** A row already moved must not be written over by the retry's backfill. */
    public function test_the_backfill_only_touches_rows_that_have_not_moved(): void
    {
        $deity = Deity::create(['name' => 'Shiva', 'slug' => 'shiva']);
        $day = DevotionalDay::create(['weekday' => 1, 'deity_id' => $deity->id, 'title' => 'Somavara']);
        $otherDay = DevotionalDay::create(['weekday' => 2, 'deity_id' => $deity->id, 'title' => 'Mangalavara']);

        $this->rewindToHalfApplied($day->id);

        // Simulates the first run having already moved this row, to a
        // different owner than the old column names.
        DB::table('devotional_media')->update([
            'mediable_type' => DevotionalDay::class,
            'mediable_id' => $otherDay->id,
        ]);

        $this->migration()->up();

        $this->assertSame($otherDay->id, (int) DevotionalMedia::firstOrFail()->mediable_id);
    }

    /**
     * Puts the table back into the shape the failed deploy left, with one
     * un-migrated row in it.
     */
    protected function rewindToHalfApplied(int $dayId): void
    {
        Schema::table('devotional_media', function (Blueprint $table) {
            $table->unsignedBigInteger('devotional_day_id')->nullable();
        });

        DB::table('devotional_media')->delete();

        DB::table('devotional_media')->insert([
            'devotional_day_id' => $dayId,
            'mediable_type' => null,
            'mediable_id' => null,
            'type' => 'song',
            'source_type' => 'external',
            'title' => 'Mid-flight',
            'external_url' => 'https://example.com/a',
            'license' => 'Licensed',
            'is_published' => true,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
