<?php

namespace Tests\Feature\Http;

use App\Enums\DevotionalMediaType;
use App\Enums\TempleStatus;
use App\Models\Deity;
use App\Models\State;
use App\Models\Temple;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A temple's song plays when its page opens, with pause and stop. */
class TemplePageSongTest extends TestCase
{
    use RefreshDatabase;

    private function temple(?Deity $deity = null): Temple
    {
        $state = State::firstOrCreate(['slug' => 'telangana'], ['name' => 'Telangana', 'code' => 'TG', 'type' => 'state']);

        return Temple::create(['name' => 'Chilkur Balaji Temple', 'slug' => 'chilkur', 'state_id' => $state->id, 'deity_id' => $deity?->id,
            'status' => TempleStatus::Published, 'published_at' => now()]);
    }

    private function deity(): Deity
    {
        return Deity::create(['name' => 'Venkateswara', 'slug' => 'venkateswara', 'is_active' => true]);
    }

    public function test_the_temples_own_song_plays_before_its_deitys(): void
    {
        $deity = $this->deity();
        $deity->media()->create(['type' => DevotionalMediaType::Song, 'title' => 'Deity song', 'external_url' => 'https://cdn.example.org/deity.mp3', 'license' => 'Public domain', 'is_published' => true]);
        $temple = $this->temple($deity);
        $temple->media()->create(['type' => DevotionalMediaType::Song, 'title' => 'Chilkur Suprabhatam', 'artist' => 'Temple choir', 'external_url' => 'https://cdn.example.org/chilkur.mp3', 'license' => 'Shared by the temple', 'is_published' => true]);

        $this->get('/temples/chilkur')->assertOk()
            ->assertSee('id="temple-song" data-src="https://cdn.example.org/chilkur.mp3"', false)
            ->assertSee('title="Chilkur Suprabhatam"', false)
            ->assertSee('aria-label="Play the temple song"', false)
            // It stops when the devotee leaves or goes back.
            ->assertSee("addEventListener('pagehide'", false);
    }

    public function test_the_deitys_song_plays_when_the_temple_has_none(): void
    {
        $deity = $this->deity();
        $deity->media()->create(['type' => DevotionalMediaType::Song, 'title' => 'Deity song', 'external_url' => 'https://cdn.example.org/deity.mp3', 'license' => 'Public domain', 'is_published' => true]);
        $this->temple($deity);

        $this->get('/temples/chilkur')->assertOk()->assertSee('data-src="https://cdn.example.org/deity.mp3"', false);
    }

    public function test_no_player_without_a_playable_published_song(): void
    {
        $temple = $this->temple();
        // A YouTube page cannot play as sound alone, and an unpublished song is not shown.
        $temple->media()->create(['type' => DevotionalMediaType::Song, 'title' => 'On YouTube', 'external_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'license' => 'Shared by the temple', 'is_published' => true]);
        $temple->media()->create(['type' => DevotionalMediaType::Song, 'title' => 'Not yet', 'external_url' => 'https://cdn.example.org/draft.mp3', 'license' => 'Shared by the temple', 'is_published' => false]);

        $this->get('/temples/chilkur')->assertOk()->assertDontSee('id="temple-song"', false);
    }
}
