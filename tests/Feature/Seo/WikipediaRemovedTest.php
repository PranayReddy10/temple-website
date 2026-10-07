<?php

namespace Tests\Feature\Seo;

use App\Enums\TempleStatus;
use App\Models\Temple;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Wikipedia is gone: its columns, its credit, and the text copied from it
 * (which may not be shown without that credit).
 */
class WikipediaRemovedTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_migration_removes_copied_text_its_translations_and_the_columns(): void
    {
        $migration = require database_path('migrations/2026_10_24_000001_remove_wikipedia_from_temples.php');
        $migration->down();

        $copied = Temple::create(['name' => 'Copied', 'slug' => 'copied', 'short_description' => 'Article opening.', 'history' => 'Article history.', 'significance' => 'Ours.', 'status' => TempleStatus::Published, 'published_at' => now()]);
        $ours = Temple::create(['name' => 'Ours', 'slug' => 'ours', 'short_description' => 'Written by us.', 'source_name' => 'Wikipedia', 'source_url' => 'https://en.wikipedia.org/wiki/Ours', 'status' => TempleStatus::Published, 'published_at' => now()]);
        DB::table('temples')->where('id', $copied->id)->update(['description_source' => 'wikipedia', 'wikipedia_fields' => json_encode(['history']), 'wikipedia_url' => 'https://en.wikipedia.org/wiki/Copied']);
        $copied->setTranslation('short_description', 'te', 'వ్యాసం.', isReviewed: true);
        $copied->setTranslation('significance', 'te', 'మాది.', isReviewed: true);

        $migration->up();

        $this->assertFalse(Schema::hasColumn('temples', 'wikipedia_url'));
        $this->assertFalse(Schema::hasColumn('temples', 'description_source'));
        $this->assertFalse(Schema::hasColumn('temples', 'wikipedia_fields'));

        $copied = $copied->fresh();
        $this->assertNull($copied->short_description);
        $this->assertNull($copied->history);
        $this->assertSame('Ours.', $copied->significance);
        $this->assertSame(['significance'], $copied->translations()->pluck('field')->all());

        $ours = $ours->fresh();
        $this->assertSame('Written by us.', $ours->short_description);
        $this->assertNull($ours->source_name);
        $this->assertNull($ours->source_url);
    }

    public function test_temple_pages_and_the_app_feed_say_nothing_of_wikipedia(): void
    {
        config(['brand.website' => 'https://darshansaathi.com']);
        Temple::create(['name' => 'Ours', 'slug' => 'ours', 'short_description' => 'Written by us.', 'status' => TempleStatus::Published, 'published_at' => now()]);

        $this->get('https://darshansaathi.com/temples/ours')->assertOk()->assertDontSee('Wikipedia');
        $this->getJson('/api/v1/temples/ours')->assertOk()
            ->assertJsonMissingPath('data.about.wikipedia_url')
            ->assertJsonMissingPath('data.about.description_credit');
    }
}
