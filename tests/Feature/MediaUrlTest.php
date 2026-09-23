<?php

namespace Tests\Feature;

use App\Models\Devotee;
use App\Models\Temple;
use App\Models\TemplePhoto;
use App\Models\VisitPhoto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Image URLs must not depend on APP_URL being right.
 *
 * It is http://localhost until someone remembers to change it, and on a
 * shared host behind Cloudflare nobody remembers until every photo in the
 * admin panel is a broken image pointing at the visitor's own machine. The
 * same class of bug that made the admin panel render unstyled.
 */
class MediaUrlTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_locally_stored_photo_url_does_not_carry_a_host(): void
    {
        Config::set('app.url', 'http://localhost');

        $url = Storage::disk('public')->url('temples/x.jpg');

        $this->assertStringStartsWith('/storage/', $url);
        $this->assertStringNotContainsString('localhost', $url);
        $this->assertStringNotContainsString('http', $url);
    }

    /** And it stays right when APP_URL is simply wrong. */
    public function test_the_url_is_unaffected_by_a_wrong_app_url(): void
    {
        Config::set('app.url', 'https://some-other-domain.example');

        $this->assertSame('/storage/temples/x.jpg', Storage::disk('public')->url('temples/x.jpg'));
    }

    public function test_a_temple_photo_resolves_through_the_same_rule(): void
    {
        Config::set('app.url', 'http://localhost');

        $temple = Temple::create(['name' => 'A Temple']);

        $photo = TemplePhoto::create([
            'temple_id' => $temple->id,
            'disk' => 'public',
            'path' => 'temples/1/darshan.jpg',
        ]);

        $this->assertStringStartsWith(url('/storage/'), $photo->url());
    }

    public function test_a_visit_photo_resolves_through_the_same_rule(): void
    {
        Config::set('app.url', 'http://localhost');

        $photo = VisitPhoto::create([
            'devotee_id' => Devotee::factory()->create()->id,
            'temple_id' => Temple::create(['name' => 'A Temple'])->id,
            'disk' => 'public',
            'original_path' => 'visit-photos/1/a.jpg',
            'stamp_path' => 'visit-photos/1/stamps/a.jpg',
        ]);

        $this->assertStringStartsWith(url('/storage/'), $photo->originalUrl());
        $this->assertStringStartsWith(url('/storage/'), $photo->stampUrl());
    }

    /**
     * The other half of the same fix.
     *
     * Filament's ImageColumn treats its state as a storage path unless it
     * validates as a URL. Handing it a finished URL worked only while that
     * URL was absolute — which it was only because APP_URL made it so. Every
     * image column now gets a path and a disk and lets the framework build
     * the URL, so no column can be broken by getting the other kind.
     */
    public function test_no_image_column_is_handed_a_finished_url(): void
    {
        $offenders = [];

        foreach ($this->filamentSources() as $file) {
            $source = file_get_contents($file);

            // An ImageColumn whose state closure calls something named …Url().
            if (preg_match('/ImageColumn::make\([^;]*?->state\([^;]*?Url\(\)/s', $source)) {
                $offenders[] = $file;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Use App\Filament\Support\MediaColumn, which takes a path and a disk.',
        );
    }

    /** @return array<int, string> */
    protected function filamentSources(): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path('Filament')),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * Spaces is genuinely another host, so its URL must stay absolute — the
     * fix above must not be applied where it would break things.
     *
     * Asserted against a URL set here rather than against whatever the
     * environment happens to hold. It holds nothing in CI and nothing on a
     * fresh clone, by design — no cloud credentials are needed to run the
     * project — so a test that only inspected the config would pass without
     * exercising anything. Setting one and reading the result back does.
     */
    public function test_the_spaces_disk_serves_absolute_urls(): void
    {
        Config::set('app.url', 'http://localhost');
        Config::set('filesystems.disks.spaces.url', 'https://cdn.example.test');
        // The adapter refuses to build without these; nothing here reaches
        // the network, so they need only be present.
        Config::set('filesystems.disks.spaces.bucket', 'temple-media');
        Config::set('filesystems.disks.spaces.key', 'test-key');
        Config::set('filesystems.disks.spaces.secret', 'test-secret');

        // The adapter is resolved once and cached, so it has to be dropped
        // for the new config to be read.
        Storage::forgetDisk('spaces');

        $this->assertSame(
            'https://cdn.example.test/temples/x.jpg',
            Storage::disk('spaces')->url('temples/x.jpg'),
        );
    }

    /**
     * And whatever the environment does hold must never be root-relative.
     *
     * The earlier version of this asserted the unset value was null. It is
     * an empty string under CI's .env.example, which sets the key with no
     * value, and null on a machine whose .env omits the key entirely —
     * blank() covers both, assertNull does not. The distinction was never
     * the point; this is.
     */
    public function test_a_configured_spaces_url_is_never_root_relative(): void
    {
        $configured = (string) config('filesystems.disks.spaces.url');

        $this->assertFalse(
            str_starts_with($configured, '/'),
            'Spaces is a different host, so its URL cannot be root-relative.',
        );

        if (filled($configured)) {
            $this->assertStringStartsWith('http', $configured);
        }
    }
}
