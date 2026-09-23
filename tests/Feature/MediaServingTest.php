<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Uploaded files have to be reachable even when the storage symlink is not.
 *
 * On shared hosting the link is the single most common reason every image in
 * the admin panel goes blank at once — and it fails silently, because the
 * requests are answered by the web server and never reach the application, so
 * nothing appears in the logs.
 */
class MediaServingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_stored_file_is_served_when_the_symlink_is_missing(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('temples/1/darshan.jpg', 'the bytes');

        $this->get('/storage/temples/1/darshan.jpg')
            ->assertOk()
            ->assertStreamedContent('the bytes');
    }

    /** A nested path has to match the single route parameter. */
    public function test_a_deeply_nested_path_is_served(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('visit-photos/12/stamps/a-b-c.jpg', 'nested');

        $this->get('/storage/visit-photos/12/stamps/a-b-c.jpg')
            ->assertOk()
            ->assertStreamedContent('nested');
    }

    public function test_a_missing_file_is_a_404_not_an_error(): void
    {
        Storage::fake('public');

        $this->get('/storage/temples/1/nothing-here.jpg')->assertNotFound();
    }

    /**
     * The files are uploaded by members of the public and served from the
     * application's own origin, so a crafted SVG must not run as same-origin
     * script.
     */
    public function test_the_response_is_sandboxed_and_not_sniffed(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('temples/1/darshan.jpg', 'the bytes');

        $response = $this->get('/storage/temples/1/darshan.jpg')->assertOk();

        $this->assertStringContainsString('sandbox', $response->headers->get('Content-Security-Policy'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    /**
     * Laravel's own ServeFile sends no-store, which would push every temple
     * photograph through PHP on every page view.
     */
    public function test_files_are_cacheable(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('temples/1/darshan.jpg', 'the bytes');

        $cacheControl = $this->get('/storage/temples/1/darshan.jpg')->headers->get('Cache-Control');

        $this->assertStringContainsString('max-age=', $cacheControl);
        $this->assertStringNotContainsString('no-store', $cacheControl);
    }

    /**
     * The failure that only shows up on a phone.
     *
     * Storage::response() streams the file and answers a Range request with
     * the whole of it and a 200. A photograph does not care. A mantra
     * recording does: seeking re-downloads from the start, and Safari and iOS
     * refuse to play audio or video at all from a server that will not serve
     * ranges — so the app goes silent for half its users while the admin
     * panel looks perfectly healthy.
     */
    public function test_a_range_request_is_answered_with_that_range(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('devotional/mantras/gayatri.mp3', str_repeat('a', 5000));

        $response = $this->call('GET', '/storage/devotional/mantras/gayatri.mp3', server: [
            'HTTP_RANGE' => 'bytes=0-999',
        ]);

        $response->assertStatus(206);
        $this->assertSame('bytes 0-999/5000', $response->headers->get('Content-Range'));
        $this->assertSame('1000', $response->headers->get('Content-Length'));
    }

    public function test_a_player_is_told_it_may_seek(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('devotional/mantras/gayatri.mp3', str_repeat('a', 5000));

        $this->get('/storage/devotional/mantras/gayatri.mp3')
            ->assertOk()
            ->assertHeader('Accept-Ranges', 'bytes');
    }

    /**
     * Typed from the extension, the way Apache types it when the symlink is
     * there. Sniffing the contents disagrees — an M4A reads as video/mp4, and
     * a host without the fileinfo extension calls everything octet-stream,
     * which a browser downloads rather than plays.
     */
    public function test_media_is_typed_the_way_the_web_server_would_type_it(): void
    {
        Storage::fake('public');

        $types = [
            'a.mp3' => 'audio/mpeg',
            'a.mp4' => 'video/mp4',
            'a.jpg' => 'image/jpeg',
            'a.png' => 'image/png',
            'a.webp' => 'image/webp',
        ];

        foreach ($types as $file => $expected) {
            Storage::disk('public')->put('devotional/'.$file, 'the bytes');

            $this->get('/storage/devotional/'.$file)
                ->assertOk()
                ->assertHeader('Content-Type', $expected);
        }
    }

    public function test_path_traversal_is_refused(): void
    {
        Storage::fake('public');

        foreach ([
            '/storage/../.env',
            '/storage/..%2F..%2F.env',
            '/storage/temples/../../../.env',
        ] as $url) {
            $response = $this->get($url);

            $this->assertContains($response->status(), [404, 301, 302], $url);
            $this->assertStringNotContainsString('APP_KEY', (string) $response->getContent(), $url);
        }
    }

    /** With media on Spaces the URLs point at the CDN; this route is not the path. */
    /**
     * The case that made the old behaviour a bug.
     *
     * This route used to decline whenever the media disk was not local, which
     * sounded careful and was wrong: /storage/{path} is the local disk's own
     * url, and a file on Spaces is delivered from the CDN and never arrives
     * here at all. All the check achieved was 404ing every photo uploaded
     * before a switch to Spaces — at the exact moment somebody needs to be
     * able to switch back and find everything intact.
     */
    public function test_older_local_files_survive_a_switch_to_spaces(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('temples/1/darshan.jpg', 'the bytes');

        Config::set('filesystems.media', 'spaces');

        $this->get('/storage/temples/1/darshan.jpg')
            ->assertOk()
            ->assertStreamedContent('the bytes');
    }
}
