<?php

namespace Tests\Unit;

use App\Support\MediaSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Working out how to play a link.
 *
 * The app cannot guess: a YouTube page inside an <audio> tag plays nothing,
 * and an .mp3 opened in a web view is a download prompt. A released build
 * also cannot be updated when a new host appears, so the classification is
 * done here and travels in the payload.
 */
class MediaSourceTest extends TestCase
{
    public static function youTubeUrls(): array
    {
        return [
            'watch' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            'watch without www' => ['https://youtube.com/watch?v=dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            'short link' => ['https://youtu.be/dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            'short link with timestamp' => ['https://youtu.be/dQw4w9WgXcQ?t=42', 'dQw4w9WgXcQ'],
            'embed' => ['https://www.youtube.com/embed/dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            'shorts' => ['https://www.youtube.com/shorts/dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            'mobile' => ['https://m.youtube.com/watch?v=dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            'music' => ['https://music.youtube.com/watch?v=dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            'with a playlist' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ&list=PL123&index=2', 'dQw4w9WgXcQ'],
            'nocookie embed' => ['https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
        ];
    }

    #[DataProvider('youTubeUrls')]
    public function test_it_reads_the_video_id_from_every_form_people_paste(string $url, string $id): void
    {
        $this->assertSame(MediaSource::YOUTUBE, MediaSource::kind($url));
        $this->assertSame($id, MediaSource::youTubeId($url));
        $this->assertSame('https://www.youtube.com/embed/'.$id, MediaSource::embedUrl($url));
    }

    public function test_a_youtube_channel_or_search_url_has_no_video_id(): void
    {
        // Still YouTube, so the client knows it is a link to open rather than
        // something to embed — but there is nothing to embed.
        foreach ([
            'https://www.youtube.com/@sometemple',
            'https://www.youtube.com/results?search_query=suprabhatam',
            'https://www.youtube.com/',
        ] as $url) {
            $this->assertSame(MediaSource::YOUTUBE, MediaSource::kind($url), $url);
            $this->assertNull(MediaSource::youTubeId($url), $url);
            $this->assertNull(MediaSource::embedUrl($url), $url);
        }
    }

    public function test_a_direct_audio_file_is_playable_as_audio(): void
    {
        foreach (['mp3', 'm4a', 'aac', 'ogg', 'opus', 'wav', 'flac'] as $extension) {
            $url = "https://cdn.example.com/chants/suprabhatam.{$extension}";

            $this->assertSame(MediaSource::AUDIO, MediaSource::kind($url), $extension);
            $this->assertTrue(MediaSource::isDirectlyPlayable(MediaSource::kind($url)));
            $this->assertFalse(MediaSource::needsEmbed(MediaSource::kind($url)));
        }
    }

    /** A query string must not stop the extension being read. */
    public function test_a_signed_url_is_still_recognised_as_audio(): void
    {
        $url = 'https://temple.blr1.digitaloceanspaces.com/chants/a.mp3?X-Amz-Signature=abc&X-Amz-Expires=900';

        $this->assertSame(MediaSource::AUDIO, MediaSource::kind($url));
    }

    public function test_an_uploaded_file_is_classified_by_its_stored_path(): void
    {
        // No external URL at all: this is the upload case.
        $this->assertSame(MediaSource::AUDIO, MediaSource::kind(null, 'devotional/deity/1/chant.mp3'));
        $this->assertSame(MediaSource::VIDEO, MediaSource::kind(null, 'devotional/day/2/aarti.mp4'));
    }

    public function test_anything_else_is_a_link_to_open(): void
    {
        foreach ([
            'https://example.com/a-page-about-a-chant',
            'https://archive.org/details/some-recording',
        ] as $url) {
            $this->assertSame(MediaSource::LINK, MediaSource::kind($url), $url);
            $this->assertFalse(MediaSource::isDirectlyPlayable(MediaSource::kind($url)));
            $this->assertFalse(MediaSource::needsEmbed(MediaSource::kind($url)));
        }
    }

    public function test_vimeo_is_embedded_rather_than_opened(): void
    {
        $this->assertSame(MediaSource::VIMEO, MediaSource::kind('https://vimeo.com/123456789'));
        $this->assertSame('https://player.vimeo.com/video/123456789', MediaSource::embedUrl('https://vimeo.com/123456789'));
    }

    /**
     * A junk id must not be turned into an embed URL that 404s. Admitting we
     * could not read the link lets the client fall back to opening it.
     */
    public function test_a_malformed_link_yields_no_id(): void
    {
        foreach ([
            'https://youtu.be/',
            'https://youtu.be/short',
            'https://www.youtube.com/watch?v=',
            'not a url at all',
            '',
        ] as $url) {
            $this->assertNull(MediaSource::youTubeId($url), var_export($url, true));
        }
    }

    public function test_nothing_at_all_is_a_link(): void
    {
        $this->assertSame(MediaSource::LINK, MediaSource::kind(null, null));
        $this->assertSame(MediaSource::LINK, MediaSource::kind('', ''));
    }
}
