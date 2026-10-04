<?php

namespace Tests\Feature\Http;

use App\Enums\DevotionalMediaType;
use App\Enums\TempleStatus;
use App\Models\State;
use App\Models\Temple;
use App\Models\TemplePhoto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A temple's photos open full size, its videos play, and nearby temples read as cards. */
class TemplePageGalleryTest extends TestCase
{
    use RefreshDatabase;

    private function temple(string $name, string $slug): Temple
    {
        $state = State::firstOrCreate(['slug' => 'telangana'], ['name' => 'Telangana', 'code' => 'TG', 'type' => 'state']);

        return Temple::create(['name' => $name, 'slug' => $slug, 'city' => 'Keesara', 'state_id' => $state->id, 'status' => TempleStatus::Published, 'published_at' => now()]);
    }

    public function test_photos_open_full_size_and_videos_play(): void
    {
        $temple = $this->temple('Keesaragutta Temple', 'keesaragutta');
        foreach (['front', 'gopuram'] as $i => $name) {
            TemplePhoto::withoutEvents(fn () => TemplePhoto::create([
                'temple_id' => $temple->id, 'disk' => 'public', 'path' => "temples/{$temple->id}/{$name}.jpg",
                'category' => 'gallery', 'caption' => ucfirst($name), 'credit' => 'Ravi K', 'license' => 'CC BY-SA 4.0',
                'is_primary' => $i === 0, 'is_published' => true,
            ]));
        }
        $temple->media()->create([
            'type' => DevotionalMediaType::Video, 'title' => 'Maha Shivaratri at Keesaragutta',
            'external_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'license' => 'Shared by the temple', 'is_published' => true,
        ]);

        $page = $this->get('/temples/keesaragutta')->assertOk();

        $page->assertSee('data-photo="1"', false)
            ->assertSee('id="lightbox"', false)
            ->assertSee('"caption":"Gopuram"', false)
            ->assertSee('Ravi K, CC BY-SA 4.0')
            ->assertSee('<h2>Videos</h2>', false)
            ->assertSee('Maha Shivaratri at Keesaragutta')
            ->assertSee('data-embed="https://www.youtube', false);
    }

    public function test_a_single_photo_still_shows_and_nearby_temples_are_cards(): void
    {
        $temple = $this->temple('Keesaragutta Temple', 'keesaragutta');
        TemplePhoto::withoutEvents(fn () => TemplePhoto::create([
            'temple_id' => $temple->id, 'disk' => 'public', 'path' => "temples/{$temple->id}/front.jpg",
            'category' => 'gallery', 'is_primary' => true, 'is_published' => true,
        ]));
        $this->temple('Chilkur Balaji Temple', 'chilkur');

        $this->get('/temples/keesaragutta')->assertOk()
            ->assertSee('data-photo="0"', false)
            ->assertDontSee('<h2>Videos</h2>', false)
            ->assertSee('<div class="txt"><b>Chilkur Balaji Temple</b>', false);
    }
}
