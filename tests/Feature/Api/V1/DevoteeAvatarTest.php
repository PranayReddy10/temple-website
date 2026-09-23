<?php

namespace Tests\Feature\Api\V1;

use App\Models\Devotee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A devotee's own photo.
 *
 * The devotees table has carried avatar_path since it was created and the API
 * has returned avatar_url all along, but nothing could write either, so every
 * profile picture was an empty circle and no amount of looking at the admin
 * would explain why. These tests exist to keep the write path attached to the
 * read path.
 */
class DevoteeAvatarTest extends TestCase
{
    use RefreshDatabase;

    protected function signIn(): Devotee
    {
        $devotee = Devotee::factory()->create();
        Sanctum::actingAs($devotee, guard: 'devotee');

        return $devotee;
    }

    public function test_a_devotee_uploads_a_photo_and_gets_a_url_back(): void
    {
        Storage::fake(config('filesystems.media'));
        $devotee = $this->signIn();

        $response = $this->post('/api/v1/me/avatar', [
            'avatar' => UploadedFile::fake()->image('me.jpg'),
        ])->assertOk();

        $devotee->refresh();

        $this->assertNotNull($devotee->avatar_path);
        Storage::disk(config('filesystems.media'))->assertExists($devotee->avatar_path);
        $this->assertNotNull($response->json('data.avatar_url'));
    }

    /**
     * The disk is written alongside the path, which is what lets the image
     * keep resolving after a move to object storage. Losing it is silent:
     * nothing breaks until the move, and then everything does at once.
     */
    public function test_the_disk_is_recorded_with_the_path(): void
    {
        Storage::fake(config('filesystems.media'));
        $devotee = $this->signIn();

        $this->post('/api/v1/me/avatar', [
            'avatar' => UploadedFile::fake()->image('me.png'),
        ])->assertOk();

        $this->assertSame(config('filesystems.media'), $devotee->refresh()->avatar_disk);
    }

    public function test_replacing_a_photo_removes_the_one_it_replaces(): void
    {
        Storage::fake(config('filesystems.media'));
        $devotee = $this->signIn();

        $this->post('/api/v1/me/avatar', ['avatar' => UploadedFile::fake()->image('first.jpg')])->assertOk();
        $first = $devotee->refresh()->avatar_path;

        $this->post('/api/v1/me/avatar', ['avatar' => UploadedFile::fake()->image('second.jpg')])->assertOk();
        $second = $devotee->refresh()->avatar_path;

        $this->assertNotSame($first, $second);
        Storage::disk(config('filesystems.media'))->assertMissing($first);
        Storage::disk(config('filesystems.media'))->assertExists($second);
    }

    public function test_a_devotee_can_remove_their_photo(): void
    {
        Storage::fake(config('filesystems.media'));
        $devotee = $this->signIn();

        $this->post('/api/v1/me/avatar', ['avatar' => UploadedFile::fake()->image('me.jpg')])->assertOk();
        $path = $devotee->refresh()->avatar_path;

        $this->deleteJson('/api/v1/me/avatar')->assertOk();

        $devotee->refresh();

        $this->assertNull($devotee->avatar_path);
        $this->assertNull($devotee->avatar_disk);
        Storage::disk(config('filesystems.media'))->assertMissing($path);
    }

    /**
     * A PDF renamed .jpg is the shape of this: the extension says image, the
     * contents do not, and Laravel's image rule reads the contents.
     */
    public function test_something_that_is_not_an_image_is_refused(): void
    {
        Storage::fake(config('filesystems.media'));
        $this->signIn();

        $this->post('/api/v1/me/avatar', [
            'avatar' => UploadedFile::fake()->create('resume.jpg', 20, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_a_photo_over_the_limit_is_refused(): void
    {
        Storage::fake(config('filesystems.media'));
        $this->signIn();

        $this->post('/api/v1/me/avatar', [
            'avatar' => UploadedFile::fake()->image('huge.jpg')->size(5000),
        ], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_a_stranger_cannot_set_somebody_a_photo(): void
    {
        Storage::fake(config('filesystems.media'));

        $this->post('/api/v1/me/avatar', [
            'avatar' => UploadedFile::fake()->image('me.jpg'),
        ], ['Accept' => 'application/json'])->assertStatus(401);
    }
}
