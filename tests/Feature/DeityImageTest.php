<?php

namespace Tests\Feature;

use App\Models\Deity;
use App\Models\User;
use App\Enums\UserRole;
use App\Services\DeityImageFinder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Deity images reach the app, and the Commons finder only ever takes a
 * public-domain painting.
 */
class DeityImageTest extends TestCase
{
    use RefreshDatabase;

    protected function fakeCommons(): void
    {
        Http::fake([
            'commons.wikimedia.org/w/api.php?*list=search*' => Http::response(['query' => ['search' => [
                ['title' => 'File:Modern Saraswati.jpg'],
                ['title' => 'File:Raja Ravi Varma, Saraswati.jpg'],
            ]]]),
            'commons.wikimedia.org/w/api.php?*prop=imageinfo*' => Http::response(['query' => ['pages' => [
                '1' => ['title' => 'File:Modern Saraswati.jpg', 'imageinfo' => [[
                    'mime' => 'image/jpeg', 'url' => 'https://upload.wikimedia.org/a.jpg', 'thumburl' => 'https://upload.wikimedia.org/thumb/a.jpg',
                    'descriptionurl' => 'https://commons.wikimedia.org/wiki/File:Modern_Saraswati.jpg',
                    'extmetadata' => ['LicenseShortName' => ['value' => 'CC BY-SA 4.0'], 'Artist' => ['value' => 'Someone']],
                ]]],
                '2' => ['title' => 'File:Raja Ravi Varma, Saraswati.jpg', 'imageinfo' => [[
                    'mime' => 'image/jpeg', 'url' => 'https://upload.wikimedia.org/b.jpg', 'thumburl' => 'https://upload.wikimedia.org/thumb/b.jpg',
                    'descriptionurl' => 'https://commons.wikimedia.org/wiki/File:Raja_Ravi_Varma,_Saraswati.jpg',
                    'extmetadata' => ['LicenseShortName' => ['value' => 'Public domain'], 'Artist' => ['value' => '<a href="x">Raja Ravi Varma</a>']],
                ]]],
            ]]]),
            'upload.wikimedia.org/*' => Http::response('JPEGBYTES', 200, ['Content-Type' => 'image/jpeg']),
        ]);
    }

    public function test_the_finder_takes_only_a_public_domain_painting(): void
    {
        $this->fakeCommons();
        $deity = Deity::create(['name' => 'Saraswati', 'slug' => 'saraswati']);

        $candidate = app(DeityImageFinder::class)->find($deity);

        $this->assertSame('File:Raja Ravi Varma, Saraswati.jpg', $candidate['title']);
        $this->assertSame('Raja Ravi Varma', $candidate['artist']);
    }

    public function test_the_command_stores_the_image_with_its_credit_and_the_api_serves_it(): void
    {
        Storage::fake(config('filesystems.media'));
        $this->fakeCommons();
        $deity = Deity::create(['name' => 'Saraswati', 'slug' => 'saraswati', 'is_active' => true]);

        $this->artisan('deities:fetch-images')->assertSuccessful();

        $deity->refresh();
        $this->assertNotNull($deity->image_path);
        Storage::disk(config('filesystems.media'))->assertExists($deity->image_path);
        $this->assertStringContainsString('Raja Ravi Varma', $deity->image_credit);
        $this->assertStringContainsString('Public domain', $deity->image_credit);

        $this->getJson('/api/v1/deities')
            ->assertOk()
            ->assertJsonPath('data.0.image_url', $deity->imageUrl())
            ->assertJsonPath('data.0.image_credit', $deity->image_credit);
    }

    public function test_an_editors_own_upload_is_never_replaced_by_the_command(): void
    {
        $this->fakeCommons();
        $deity = Deity::create(['name' => 'Saraswati', 'slug' => 'saraswati', 'image_path' => 'deities/editor-upload.jpg']);

        $this->artisan('deities:fetch-images', ['--replace' => true])->assertSuccessful();

        $this->assertSame('deities/editor-upload.jpg', $deity->fresh()->image_path);
        Http::assertNothingSent();
    }

    public function test_nothing_is_taken_when_commons_has_no_public_domain_file(): void
    {
        Http::fake([
            'commons.wikimedia.org/w/api.php?*list=search*' => Http::response(['query' => ['search' => [['title' => 'File:Photo.jpg']]]]),
            'commons.wikimedia.org/w/api.php?*prop=imageinfo*' => Http::response(['query' => ['pages' => ['1' => ['title' => 'File:Photo.jpg', 'imageinfo' => [[
                'mime' => 'image/jpeg', 'url' => 'https://upload.wikimedia.org/p.jpg', 'descriptionurl' => 'x',
                'extmetadata' => ['LicenseShortName' => ['value' => 'CC BY 4.0']],
            ]]]]]]),
        ]);
        $deity = Deity::create(['name' => 'Ayyappa', 'slug' => 'ayyappa']);

        $this->assertNull(app(DeityImageFinder::class)->find($deity));
    }

    public function test_the_deity_pages_render_with_the_image_actions(): void
    {
        $deity = Deity::create(['name' => 'Saraswati', 'slug' => 'saraswati']);
        $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin, 'is_active' => true]));

        $this->get('/admin/deities')->assertOk()->assertSee('Find images for all');
        $this->get("/admin/deities/{$deity->getRouteKey()}/edit")->assertOk()->assertSee('Find a public-domain image');
    }
}
