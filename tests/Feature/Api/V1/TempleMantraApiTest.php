<?php

namespace Tests\Feature\Api\V1;

use App\Enums\DevotionalMediaType;
use App\Enums\TempleStatus;
use App\Models\Deity;
use App\Models\Temple;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The mantra and songs a devotee gets when they open a temple.
 *
 * The fallback is the whole feature: most temples will never have their own
 * verse or recording, and a temple page that renders a heading with nothing
 * under it reads as broken rather than as a temple without one.
 */
class TempleMantraApiTest extends TestCase
{
    use RefreshDatabase;

    protected function vishnu(): Deity
    {
        return Deity::create([
            'name' => 'Vishnu',
            'slug' => 'vishnu',
            'mantra' => 'ॐ नमो नारायणाय',
            'mantra_transliteration' => 'Om Namo Narayanaya',
            'mantra_meaning' => 'Salutations to Narayana.',
        ]);
    }

    public function test_a_temple_without_its_own_mantra_serves_its_deity_s(): void
    {
        $temple = Temple::create([
            'name' => 'A Vishnu Temple',
            'deity_id' => $this->vishnu()->id,
            'status' => TempleStatus::Published,
        ]);

        $response = $this->getJson("/api/v1/temples/{$temple->slug}")->assertOk();

        $this->assertSame('ॐ नमो नारायणाय', $response->json('data.mantra.text'));
        $this->assertSame('Om Namo Narayanaya', $response->json('data.mantra.transliteration'));
        // The app renders the two differently, so it has to be told which.
        $this->assertFalse($response->json('data.mantra.is_temple_specific'));
    }

    public function test_a_temple_with_its_own_mantra_serves_that(): void
    {
        $temple = Temple::create([
            'name' => 'Tirumala',
            'deity_id' => $this->vishnu()->id,
            'status' => TempleStatus::Published,
            'mantra' => 'कौसल्या सुप्रजा राम',
            'mantra_transliteration' => 'Kausalya Supraja Rama',
        ]);

        $response = $this->getJson("/api/v1/temples/{$temple->slug}")->assertOk();

        $this->assertSame('कौसल्या सुप्रजा राम', $response->json('data.mantra.text'));
        $this->assertTrue($response->json('data.mantra.is_temple_specific'));
    }

    public function test_a_temple_serves_its_own_songs_before_its_deity_s(): void
    {
        $deity = $this->vishnu();
        $temple = Temple::create([
            'name' => 'Tirumala',
            'deity_id' => $deity->id,
            'status' => TempleStatus::Published,
        ]);

        $deity->media()->create([
            'type' => DevotionalMediaType::Chant,
            'title' => 'Vishnu Sahasranama',
            'external_url' => 'https://example.com/deity',
            'is_published' => true,
        ]);

        $temple->media()->create([
            'type' => DevotionalMediaType::Chant,
            'title' => 'Venkatesa Suprabhatam',
            'external_url' => 'https://example.com/temple',
            'is_published' => true,
        ]);

        $titles = collect($this->getJson("/api/v1/temples/{$temple->slug}")->assertOk()
            ->json('data.devotional_media'))->pluck('title');

        $this->assertSame(['Venkatesa Suprabhatam', 'Vishnu Sahasranama'], $titles->all());
    }

    /** The rights rule does not soften because the owner changed. */
    public function test_an_unlicensed_song_still_never_reaches_the_api(): void
    {
        $temple = Temple::create([
            'name' => 'Tirumala',
            'deity_id' => $this->vishnu()->id,
            'status' => TempleStatus::Published,
        ]);

        $temple->media()->create([
            'type' => DevotionalMediaType::Song,
            'title' => 'Unlicensed',
            'external_url' => 'https://example.com/a',
            'is_published' => true,   // forced false by the rights rule
        ]);

        $this->assertCount(
            0,
            $this->getJson("/api/v1/temples/{$temple->slug}")->assertOk()->json('data.devotional_media'),
        );
    }

    public function test_the_day_endpoint_carries_the_deity_image_and_meaning(): void
    {
        $deity = $this->vishnu();
        $deity->update(['image_disk' => 'public', 'image_path' => 'deities/vishnu.jpg']);

        \App\Models\DevotionalDay::create([
            'weekday' => 4,
            'deity_id' => $deity->id,
            'title' => 'Guruvara',
        ]);

        $response = $this->getJson('/api/v1/days/4')->assertOk();

        $this->assertStringContainsString('/storage/deities/vishnu.jpg', $response->json('data.0.deity.image_url'));
        $this->assertSame('Salutations to Narayana.', $response->json('data.0.deity.mantra_meaning'));
        // The day had no mantra of its own, so it falls back to the deity's.
        $this->assertSame('ॐ नमो नारायणाय', $response->json('data.0.mantra'));
    }

    public function test_a_day_with_its_own_mantra_keeps_it(): void
    {
        \App\Models\DevotionalDay::create([
            'weekday' => 4,
            'deity_id' => $this->vishnu()->id,
            'title' => 'Guruvara',
            'mantra' => 'ॐ गुरवे नमः',
        ]);

        $this->getJson('/api/v1/days/4')
            ->assertOk()
            ->assertJsonPath('data.0.mantra', 'ॐ गुरवे नमः');
    }
}
