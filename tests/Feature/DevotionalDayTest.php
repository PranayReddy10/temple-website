<?php

namespace Tests\Feature;

use App\Enums\DevotionalMediaType;
use App\Enums\TempleStatus;
use App\Models\Deity;
use App\Models\DevotionalDay;
use App\Models\DevotionalMedia;
use App\Models\Temple;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DevotionalDayTest extends TestCase
{
    use RefreshDatabase;

    protected function shivaMonday(): DevotionalDay
    {
        $shiva = Deity::create(['name' => 'Shiva', 'slug' => 'shiva']);

        return DevotionalDay::create([
            'weekday' => 1,
            'deity_id' => $shiva->id,
            'title' => 'Somavara — Shiva',
            'mantra_transliteration' => 'Om Namah Shivaya',
            'accent_color' => '#6B7FA8',
        ]);
    }

    // --- The mapping ---

    public function test_monday_resolves_to_shiva(): void
    {
        $day = $this->shivaMonday();

        $found = DevotionalDay::active()
            ->forDate(Carbon::parse('2026-09-21'))  // a Monday
            ->with('deity')
            ->first();

        $this->assertTrue($found->is($day));
        $this->assertSame('Shiva', $found->deity->name);
    }

    public function test_a_day_can_hold_more_than_one_deity(): void
    {
        $hanuman = Deity::create(['name' => 'Hanuman', 'slug' => 'hanuman']);
        $ganesha = Deity::create(['name' => 'Ganesha', 'slug' => 'ganesha']);

        DevotionalDay::create(['weekday' => 2, 'deity_id' => $hanuman->id, 'title' => 'Hanuman', 'sort_order' => 1]);
        DevotionalDay::create(['weekday' => 2, 'deity_id' => $ganesha->id, 'title' => 'Ganesha', 'sort_order' => 2]);

        // Regional traditions differ, so the weekday alone is not unique.
        $this->assertSame(2, DevotionalDay::where('weekday', 2)->count());
    }

    public function test_the_day_surfaces_temples_of_its_deity(): void
    {
        $day = $this->shivaMonday();
        Temple::create(['name' => 'Shiva Temple', 'deity_id' => $day->deity_id, 'status' => TempleStatus::Published]);
        Temple::create(['name' => 'Draft Shiva Temple', 'deity_id' => $day->deity_id, 'status' => TempleStatus::Draft]);
        Temple::create(['name' => 'Other Temple', 'status' => TempleStatus::Published]);

        $temples = $day->temples()->get();

        $this->assertCount(1, $temples);
        $this->assertSame('Shiva Temple', $temples->first()->name);
    }

    public function test_it_falls_back_to_the_brand_colour(): void
    {
        $day = $this->shivaMonday();
        $day->update(['accent_color' => null]);

        $this->assertSame(config('brand.colors.saffron.hex'), $day->fresh()->accentColor());
    }

    // --- Rights ---

    public function test_a_song_without_a_licence_cannot_be_published(): void
    {
        $day = $this->shivaMonday();

        $media = DevotionalMedia::create([
            'devotional_day_id' => $day->id,
            'type' => DevotionalMediaType::Song,
            'title' => 'Shiva Tandava Stotram',
            'external_url' => 'https://example.com/song',
            'is_published' => true,
        ]);

        // A recording belongs to its performer even when the composition is
        // ancient. Forced back to unpublished rather than rejected, so the
        // draft survives while the rights are chased.
        $this->assertFalse($media->fresh()->is_published);
        $this->assertFalse($media->mayBePublished());
    }

    public function test_a_licensed_song_publishes(): void
    {
        $day = $this->shivaMonday();

        $media = DevotionalMedia::create([
            'devotional_day_id' => $day->id,
            'type' => DevotionalMediaType::Song,
            'title' => 'Licensed Bhajan',
            'external_url' => 'https://example.com/song',
            'license' => 'CC BY-SA 4.0',
            'is_published' => true,
        ]);

        $this->assertTrue($media->fresh()->is_published);
    }

    public function test_a_photo_needs_no_licence(): void
    {
        $day = $this->shivaMonday();

        $media = DevotionalMedia::create([
            'devotional_day_id' => $day->id,
            'type' => DevotionalMediaType::Photo,
            'title' => 'Temple at dawn',
            'external_url' => 'https://example.com/photo.jpg',
            'is_published' => true,
        ]);

        $this->assertTrue($media->fresh()->is_published);
    }

    public function test_a_link_and_an_upload_are_mutually_exclusive(): void
    {
        $day = $this->shivaMonday();

        $media = DevotionalMedia::create([
            'devotional_day_id' => $day->id,
            'type' => DevotionalMediaType::Photo,
            'title' => 'Both set',
            'source_type' => 'external',
            'external_url' => 'https://example.com/a.jpg',
            'path' => 'devotional/a.jpg',
        ]);

        // Keeping both would make url() ambiguous.
        $this->assertNull($media->fresh()->path);
        $this->assertSame('https://example.com/a.jpg', $media->fresh()->url());
    }

    // --- API ---

    public function test_the_today_endpoint_returns_the_days_deity_and_colour(): void
    {
        $this->shivaMonday();
        Carbon::setTestNow(Carbon::parse('2026-09-21 08:00:00'));  // Monday

        $this->getJson('/api/v1/today')
            ->assertOk()
            ->assertJsonPath('data.weekday_name', 'Monday')
            ->assertJsonPath('data.accent_color', '#6B7FA8')
            ->assertJsonPath('data.days.0.deity.name', 'Shiva')
            ->assertJsonPath('data.days.0.mantra_transliteration', 'Om Namah Shivaya');

        Carbon::setTestNow();
    }

    public function test_today_includes_temples_of_the_days_deity(): void
    {
        $day = $this->shivaMonday();
        Temple::create(['name' => 'Kedarnath', 'deity_id' => $day->deity_id, 'status' => TempleStatus::Published]);
        Carbon::setTestNow(Carbon::parse('2026-09-21 08:00:00'));

        $this->getJson('/api/v1/today')
            ->assertOk()
            ->assertJsonPath('data.days.0.temples.0.name', 'Kedarnath');

        Carbon::setTestNow();
    }

    public function test_unpublished_media_never_reaches_the_api(): void
    {
        $day = $this->shivaMonday();

        DevotionalMedia::create([
            'devotional_day_id' => $day->id,
            'type' => DevotionalMediaType::Song,
            'title' => 'Unlicensed',
            'external_url' => 'https://example.com/a',
            'is_published' => true,   // forced false by the rights rule
        ]);
        DevotionalMedia::create([
            'devotional_day_id' => $day->id,
            'type' => DevotionalMediaType::Song,
            'title' => 'Licensed',
            'external_url' => 'https://example.com/b',
            'license' => 'Licensed from the label',
            'is_published' => true,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-09-21 08:00:00'));

        $response = $this->getJson('/api/v1/today')->assertOk();

        $this->assertCount(1, $response->json('data.days.0.media'));
        $this->assertSame('Licensed', $response->json('data.days.0.media.0.title'));

        Carbon::setTestNow();
    }

    public function test_media_carries_its_attribution_to_the_client(): void
    {
        $day = $this->shivaMonday();
        DevotionalMedia::create([
            'devotional_day_id' => $day->id,
            'type' => DevotionalMediaType::Song,
            'title' => 'Bhajan',
            'external_url' => 'https://example.com/b',
            'artist' => 'A Performer',
            'license' => 'CC BY 4.0',
            'is_published' => true,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-09-21 08:00:00'));

        // A client that plays it without these strips the licence terms.
        $this->getJson('/api/v1/today')
            ->assertOk()
            ->assertJsonPath('data.days.0.media.0.artist', 'A Performer')
            ->assertJsonPath('data.days.0.media.0.license', 'CC BY 4.0');

        Carbon::setTestNow();
    }

    public function test_an_inactive_day_is_hidden(): void
    {
        $day = $this->shivaMonday();
        $day->update(['is_active' => false]);

        Carbon::setTestNow(Carbon::parse('2026-09-21 08:00:00'));

        $this->getJson('/api/v1/today')
            ->assertOk()
            ->assertJsonCount(0, 'data.days');

        Carbon::setTestNow();
    }

    public function test_the_week_can_be_listed_and_a_day_fetched(): void
    {
        $this->shivaMonday();

        $this->getJson('/api/v1/days')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/days/1')->assertOk()->assertJsonPath('data.0.deity.name', 'Shiva');
        $this->getJson('/api/v1/days/9')->assertNotFound();
        $this->getJson('/api/v1/days/3')->assertNotFound();
    }
}
