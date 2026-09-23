<?php

namespace Tests\Feature;

use App\Enums\DevotionalMediaType;
use App\Enums\TempleStatus;
use App\Models\Deity;
use App\Models\DevotionalDay;
use App\Models\Temple;
use App\Support\MediaSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A mantra a devotee can hear, not only read.
 *
 * Two rules carry it. The fallback runs field by field rather than
 * all-or-nothing — a temple with its own verse but no recording shows its
 * verse and plays its deity's chant, because falling back wholesale would
 * show the wrong verse. And the pointer is not a way round the rights rule:
 * an unpublished recording is not played however it is attached.
 */
class MantraRecordingTest extends TestCase
{
    use RefreshDatabase;

    protected function shiva(): Deity
    {
        return Deity::create([
            'name' => 'Shiva',
            'slug' => 'shiva',
            'mantra' => 'ॐ नमः शिवाय',
            'mantra_transliteration' => 'Om Namah Shivaya',
            'mantra_meaning' => 'Salutations to Shiva.',
        ]);
    }

    protected function recordingFor(object $owner, array $attributes = []): object
    {
        return $owner->media()->create(array_merge([
            'type' => DevotionalMediaType::Chant,
            'title' => 'A chant',
            'external_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'is_published' => true,
        ], $attributes));
    }

    // --- The fallback chain ---

    public function test_a_temple_plays_its_deity_s_recording_when_it_has_none(): void
    {
        $deity = $this->shiva();
        $chant = $this->recordingFor($deity, ['title' => 'Deity chant']);
        $deity->update(['mantra_media_id' => $chant->id]);

        $temple = Temple::create(['name' => 'A Shiva Temple', 'deity_id' => $deity->id]);

        $this->assertSame('Deity chant', $temple->mantraRecording()?->title);
        $this->assertTrue($temple->hasMantraRecording());
        $this->assertFalse($temple->mantraIsOwn());
    }

    public function test_a_temple_s_own_recording_wins(): void
    {
        $deity = $this->shiva();
        $deityChant = $this->recordingFor($deity, ['title' => 'Deity chant']);
        $deity->update(['mantra_media_id' => $deityChant->id]);

        $temple = Temple::create([
            'name' => 'Tirumala',
            'deity_id' => $deity->id,
            'mantra' => 'कौसल्या सुप्रजा राम',
        ]);
        $templeChant = $this->recordingFor($temple, ['title' => 'Suprabhatam']);
        $temple->update(['mantra_media_id' => $templeChant->id]);

        $this->assertSame('Suprabhatam', $temple->fresh()->mantraRecording()?->title);
        $this->assertTrue($temple->fresh()->mantraIsOwn());
    }

    /**
     * Field by field, not all-or-nothing: the verse is the temple's, the
     * audio is the deity's. Falling back wholesale would play a chant of a
     * different verse than the one on screen.
     */
    public function test_its_own_verse_plays_the_deity_s_recording(): void
    {
        $deity = $this->shiva();
        $chant = $this->recordingFor($deity, ['title' => 'Deity chant']);
        $deity->update(['mantra_media_id' => $chant->id]);

        $temple = Temple::create([
            'name' => 'Tirumala',
            'deity_id' => $deity->id,
            'mantra' => 'कौसल्या सुप्रजा राम',
        ]);

        $this->assertSame('कौसल्या सुप्रजा राम', $temple->mantraText());
        $this->assertSame('Deity chant', $temple->mantraRecording()?->title);
    }

    public function test_a_weekday_falls_back_the_same_way(): void
    {
        $deity = $this->shiva();
        $chant = $this->recordingFor($deity, ['title' => 'Deity chant']);
        $deity->update(['mantra_media_id' => $chant->id]);

        $day = DevotionalDay::create(['weekday' => 1, 'deity_id' => $deity->id, 'title' => 'Somavara']);

        $this->assertSame('ॐ नमः शिवाय', $day->mantraText());
        $this->assertSame('Deity chant', $day->mantraRecording()?->title);
    }

    public function test_nothing_anywhere_means_no_recording(): void
    {
        $temple = Temple::create(['name' => 'Bare', 'deity_id' => $this->shiva()->id]);

        $this->assertNull($temple->mantraRecording());
        $this->assertFalse($temple->hasMantraRecording());
        // But the text still comes through.
        $this->assertTrue($temple->hasMantra());
    }

    /** The pointer is not a way round the rule the rest of the media goes through. */
    public function test_an_unpublished_recording_is_not_played(): void
    {
        $deity = $this->shiva();
        $draft = $this->recordingFor($deity, ['title' => 'Draft', 'is_published' => false]);
        $deity->update(['mantra_media_id' => $draft->id]);

        $this->assertNull($deity->fresh()->mantraRecording());
    }

    /** A song with no licence is forced unpublished, so it is not played either. */
    public function test_an_unlicensed_song_attached_as_a_mantra_is_not_played(): void
    {
        $deity = $this->shiva();

        $song = $this->recordingFor($deity, [
            'type' => DevotionalMediaType::Song,
            'title' => 'Unlicensed',
            'is_published' => true,   // forced false by the rights rule
        ]);
        $deity->update(['mantra_media_id' => $song->id]);

        $this->assertNull($deity->fresh()->mantraRecording());
    }

    /** Deleting a recording must not delete the deity that pointed at it. */
    public function test_deleting_a_recording_leaves_the_mantra_text_alone(): void
    {
        $deity = $this->shiva();
        $chant = $this->recordingFor($deity);
        $deity->update(['mantra_media_id' => $chant->id]);

        $chant->delete();
        $deity->refresh();

        $this->assertTrue($deity->exists);
        $this->assertNull($deity->mantra_media_id);
        $this->assertSame('ॐ नमः शिवाय', $deity->mantraText());
    }

    // --- What the API serves ---

    public function test_the_temple_endpoint_says_how_to_play_a_youtube_mantra(): void
    {
        $deity = $this->shiva();
        $chant = $this->recordingFor($deity, [
            'external_url' => 'https://youtu.be/dQw4w9WgXcQ',
            'artist' => 'A Temple Choir',
        ]);
        $deity->update(['mantra_media_id' => $chant->id]);

        $temple = Temple::create([
            'name' => 'A Shiva Temple',
            'deity_id' => $deity->id,
            'status' => TempleStatus::Published,
        ]);

        $audio = $this->getJson("/api/v1/temples/{$temple->slug}")
            ->assertOk()
            ->json('data.mantra.audio');

        $this->assertSame(MediaSource::YOUTUBE, $audio['playback']['kind']);
        $this->assertTrue($audio['playback']['needs_embed']);
        $this->assertFalse($audio['playback']['is_playable']);
        $this->assertSame('dQw4w9WgXcQ', $audio['playback']['youtube_id']);
        $this->assertSame('https://www.youtube.com/embed/dQw4w9WgXcQ', $audio['playback']['embed_url']);
        // Rights travel with it, as with everything else we play.
        $this->assertSame('A Temple Choir', $audio['artist']);
    }

    public function test_an_mp3_link_is_served_as_directly_playable(): void
    {
        $deity = $this->shiva();
        $chant = $this->recordingFor($deity, [
            'external_url' => 'https://cdn.example.com/chants/om-namah-shivaya.mp3',
        ]);
        $deity->update(['mantra_media_id' => $chant->id]);

        $temple = Temple::create([
            'name' => 'A Shiva Temple',
            'deity_id' => $deity->id,
            'status' => TempleStatus::Published,
        ]);

        $audio = $this->getJson("/api/v1/temples/{$temple->slug}")->assertOk()->json('data.mantra.audio');

        $this->assertSame(MediaSource::AUDIO, $audio['playback']['kind']);
        $this->assertTrue($audio['playback']['is_playable']);
        $this->assertFalse($audio['playback']['needs_embed']);
        $this->assertNull($audio['playback']['embed_url']);
    }

    public function test_an_uploaded_file_is_served_as_directly_playable(): void
    {
        $deity = $this->shiva();
        $chant = $this->recordingFor($deity, [
            'source_type' => 'upload',
            'external_url' => null,
            'disk' => 'public',
            'path' => 'devotional/mantras/om.mp3',
        ]);
        $deity->update(['mantra_media_id' => $chant->id]);

        $temple = Temple::create([
            'name' => 'A Shiva Temple',
            'deity_id' => $deity->id,
            'status' => TempleStatus::Published,
        ]);

        $audio = $this->getJson("/api/v1/temples/{$temple->slug}")->assertOk()->json('data.mantra.audio');

        $this->assertSame(MediaSource::AUDIO, $audio['playback']['kind']);
        $this->assertTrue($audio['playback']['is_playable']);
        $this->assertStringStartsWith(url('/storage/'), $audio['url']);
    }

    /** A mantra with no recording is the normal case, and must not be a broken player. */
    public function test_a_mantra_with_no_recording_serves_null_audio(): void
    {
        $temple = Temple::create([
            'name' => 'A Shiva Temple',
            'deity_id' => $this->shiva()->id,
            'status' => TempleStatus::Published,
        ]);

        $mantra = $this->getJson("/api/v1/temples/{$temple->slug}")->assertOk()->json('data.mantra');

        $this->assertSame('ॐ नमः शिवाय', $mantra['text']);
        $this->assertNull($mantra['audio']);
    }

    public function test_a_temple_with_no_mantra_at_all_serves_null(): void
    {
        $temple = Temple::create(['name' => 'Bare', 'status' => TempleStatus::Published]);

        $this->assertNull(
            $this->getJson("/api/v1/temples/{$temple->slug}")->assertOk()->json('data.mantra'),
        );
    }

    public function test_the_day_endpoint_carries_the_mantra_audio_too(): void
    {
        $deity = $this->shiva();
        $chant = $this->recordingFor($deity, ['external_url' => 'https://cdn.example.com/om.mp3']);
        $deity->update(['mantra_media_id' => $chant->id]);

        DevotionalDay::create(['weekday' => 1, 'deity_id' => $deity->id, 'title' => 'Somavara']);

        $response = $this->getJson('/api/v1/days/1')->assertOk();

        $this->assertSame('ॐ नमः शिवाय', $response->json('data.0.mantra_audio.text'));
        $this->assertSame(MediaSource::AUDIO, $response->json('data.0.mantra_audio.audio.playback.kind'));
        // The flat fields stay for the app already reading them.
        $this->assertSame('ॐ नमः शिवाय', $response->json('data.0.mantra'));
    }
}
