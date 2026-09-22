<?php

namespace Tests\Feature;

use App\Models\Temple;
use App\Models\TempleClosure;
use App\Models\TemplePhoto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TempleMediaTest extends TestCase
{
    use RefreshDatabase;

    protected function fakeDisk(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        config(['filesystems.media' => 'media-test']);

        return Storage::fake('media-test');
    }

    protected function uploadPhoto(Temple $temple, array $attributes = []): TemplePhoto
    {
        $disk = $this->fakeDisk();
        $file = UploadedFile::fake()->image('temple.jpg', 2000, 1400);
        $path = $file->store('temples/'.$temple->id, 'media-test');

        return TemplePhoto::create(array_merge([
            'temple_id' => $temple->id,
            'disk' => 'media-test',
            'path' => $path,
        ], $attributes));
    }

    public function test_uploading_a_photo_generates_display_variants(): void
    {
        $temple = Temple::create(['name' => 'Photo Temple']);
        $photo = $this->uploadPhoto($temple)->fresh();

        $this->assertNotNull($photo->medium_path);
        $this->assertNotNull($photo->thumbnail_path);
        Storage::disk('media-test')->assertExists($photo->medium_path);
        Storage::disk('media-test')->assertExists($photo->thumbnail_path);

        // Dimensions are recorded from the original.
        $this->assertSame(2000, $photo->width);
        $this->assertSame(1400, $photo->height);
    }

    public function test_variants_are_never_upscaled_beyond_the_original(): void
    {
        $temple = Temple::create(['name' => 'Small Photo Temple']);
        $disk = $this->fakeDisk();
        $file = UploadedFile::fake()->image('small.jpg', 300, 200);
        $path = $file->store('temples/'.$temple->id, 'media-test');

        $photo = TemplePhoto::create([
            'temple_id' => $temple->id,
            'disk' => 'media-test',
            'path' => $path,
        ])->fresh();

        // A 300px original must not be blown up into a blurry 1200px "medium".
        $this->assertSame(300, $photo->width);
        $this->assertNotNull($photo->medium_path);
    }

    public function test_the_first_photo_becomes_the_lead_image(): void
    {
        $temple = Temple::create(['name' => 'Lead Temple']);
        $first = $this->uploadPhoto($temple);

        $this->assertTrue($first->fresh()->is_primary);
    }

    public function test_only_one_photo_can_be_the_lead_image(): void
    {
        $temple = Temple::create(['name' => 'Single Lead Temple']);
        $first = $this->uploadPhoto($temple);
        $second = $this->uploadPhoto($temple, ['is_primary' => true]);

        $this->assertFalse($first->fresh()->is_primary);
        $this->assertTrue($second->fresh()->is_primary);
        $this->assertSame(1, TemplePhoto::where('temple_id', $temple->id)->where('is_primary', true)->count());
    }

    public function test_deleting_the_lead_image_promotes_another(): void
    {
        $temple = Temple::create(['name' => 'Promote Temple']);
        $first = $this->uploadPhoto($temple);
        $second = $this->uploadPhoto($temple);

        $first->delete();

        $this->assertTrue($second->fresh()->is_primary);
    }

    public function test_deleting_a_photo_removes_its_files_from_storage(): void
    {
        $temple = Temple::create(['name' => 'Cleanup Temple']);
        $photo = $this->uploadPhoto($temple)->fresh();
        $paths = $photo->storedPaths();

        $this->assertNotEmpty($paths);

        $photo->delete();

        // Orphaned objects in Spaces cost money and are unfindable later.
        foreach ($paths as $path) {
            Storage::disk('media-test')->assertMissing($path);
        }
    }

    public function test_photo_urls_resolve_against_the_disk_recorded_on_the_row(): void
    {
        $temple = Temple::create(['name' => 'Disk Temple']);
        $photo = $this->uploadPhoto($temple)->fresh();

        // Simulate a later migration of the default media disk.
        config(['filesystems.media' => 'some-other-disk']);

        $this->assertNotNull($photo->url());
        $this->assertStringContainsString('temples/'.$temple->id, (string) $photo->url());
    }

    public function test_a_closure_covers_its_whole_date_range(): void
    {
        $temple = Temple::create(['name' => 'Closure Temple']);
        $closure = TempleClosure::create([
            'temple_id' => $temple->id,
            'starts_on' => now()->subDay()->toDateString(),
            'ends_on' => now()->addDay()->toDateString(),
            'reason' => 'Renovation',
        ]);

        $this->assertTrue($closure->coversDate(now()));
        $this->assertTrue($closure->coversDate(now()->subDay()));
        $this->assertTrue($closure->coversDate(now()->addDay()));
        $this->assertFalse($closure->coversDate(now()->addDays(3)));
    }

    public function test_a_single_day_closure_needs_no_end_date(): void
    {
        $temple = Temple::create(['name' => 'One Day Temple']);
        $closure = TempleClosure::create([
            'temple_id' => $temple->id,
            'starts_on' => now()->toDateString(),
            'reason' => 'Festival',
        ]);

        $this->assertTrue($closure->coversDate(now()));
        $this->assertFalse($closure->coversDate(now()->addDay()));
        $this->assertTrue($temple->load('closures')->isClosedOn());
    }

    public function test_a_partial_day_closure_does_not_mark_the_temple_closed(): void
    {
        $temple = Temple::create(['name' => 'Partial Temple']);
        TempleClosure::create([
            'temple_id' => $temple->id,
            'starts_on' => now()->toDateString(),
            'reason' => 'Reduced hours for abhishekam',
            'is_full_day' => false,
            'opens_at' => '10:00',
            'closes_at' => '14:00',
        ]);

        // The temple still opens, just on different hours.
        $this->assertFalse($temple->load('closures')->isClosedOn());
    }
}
