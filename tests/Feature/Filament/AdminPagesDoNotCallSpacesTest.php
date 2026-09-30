<?php

namespace Tests\Feature\Filament;

use App\Enums\TempleStatus;
use App\Enums\UserRole;
use App\Models\Temple;
use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * The admin must render without a round trip to the Space: a slow or
 * refusing Space (a bad key, a stalled connection) held these pages open.
 */
class AdminPagesDoNotCallSpacesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_temples_list_photos_list_and_edit_page_never_ask_the_space(): void
    {
        $spaces = Mockery::mock(FilesystemAdapter::class);
        $spaces->shouldReceive('url')->andReturnUsing(fn (string $p): string => 'https://cdn.example/'.$p);
        foreach (['exists', 'size', 'mimeType', 'temporaryUrl', 'get', 'readStream', 'getVisibility'] as $call) {
            $spaces->shouldNotReceive($call);
        }
        Storage::set('spaces', $spaces);

        $temple = Temple::create(['name' => 'Cover Temple', 'slug' => 'cover-temple', 'status' => TempleStatus::Published]);
        $temple->photos()->createQuietly(['disk' => 'spaces', 'path' => 'temples/covers/a.jpg', 'thumbnail_path' => 'temples/covers/a-thumb.webp', 'is_primary' => true, 'is_published' => true]);

        $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin, 'is_active' => true]), 'web');

        $this->get('/admin/temples')->assertOk()->assertSee('https://cdn.example/temples/covers/a-thumb.webp', false);
        $this->get('/admin/temple-photos')->assertOk();
        $this->get("/admin/temples/{$temple->id}/edit")->assertOk();
    }
}
