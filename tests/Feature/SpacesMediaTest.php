<?php

namespace Tests\Feature;

use App\Enums\TempleStatus;
use App\Models\Temple;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Photos on the Space reach the website through this host, the phones through the CDN. */
class SpacesMediaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['filesystems.disks.spaces' => array_merge(config('filesystems.disks.spaces'), ['key' => 'k', 'secret' => 's', 'bucket' => 'b', 'endpoint' => 'https://sgp1.digitaloceanspaces.com'])]);
        Storage::fake('spaces');
        Storage::disk('spaces')->put('temples/covers/a-medium.webp', 'webp bytes');

        $t = Temple::create(['name' => 'Cover Temple', 'slug' => 'cover-temple', 'status' => TempleStatus::Published, 'published_at' => now()]);
        $t->photos()->createQuietly(['disk' => 'spaces', 'path' => 'temples/covers/a-medium.webp', 'is_primary' => true, 'is_published' => true]);
    }

    public function test_a_browser_gets_the_photo_from_this_host_with_cors(): void
    {
        $url = $this->getJson('/api/v1/temples', ['Origin' => 'https://darshansaathi.com'])->assertOk()
            ->json('data.0.primary_photo.urls.medium');

        $this->assertSame(url('/media/temples/covers/a-medium.webp'), $url);

        $this->get('/media/temples/covers/a-medium.webp')->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', '*')
            ->assertHeader('Content-Type', 'image/webp')
            ->assertStreamedContent('webp bytes');
    }

    public function test_a_phone_keeps_the_cdn_address(): void
    {
        $url = $this->getJson('/api/v1/temples')->assertOk()->json('data.0.primary_photo.urls.medium');
        $this->assertStringNotContainsString('/media/', $url);
    }

    public function test_only_images_and_never_outside_the_bucket(): void
    {
        Storage::disk('spaces')->put('backups/db.sql', 'secret');
        $this->get('/media/backups/db.sql')->assertNotFound();
        $this->get('/media/temples/../backups/x.webp')->assertNotFound();
        $this->get('/media/temples/covers/missing.webp')->assertNotFound();
    }
}
