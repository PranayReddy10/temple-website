<?php

namespace Tests\Feature\Api\V1;

use App\Enums\PhotoModerationStatus;
use App\Enums\TempleStatus;
use App\Enums\UserRole;
use App\Models\Devotee;
use App\Models\Temple;
use App\Models\TemplePhoto;
use App\Models\TempleUser;
use App\Models\User;
use App\Models\VisitPhoto;
use App\Support\PhotoPromotion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/** Approved Photo Stamps, promoted into a temple's own gallery and credited. */
class DevoteePhotoGalleryTest extends TestCase
{
    use RefreshDatabase;

    protected Temple $temple;

    protected function setUp(): void
    {
        parent::setUp();
        config(['filesystems.media' => 'gallery-test']);
        Storage::fake('gallery-test');
        $this->temple = Temple::create(['name' => 'Gallery Temple', 'slug' => 'gallery-temple', 'status' => TempleStatus::Published, 'published_at' => now()]);
    }

    protected function upload(bool $public = true): VisitPhoto
    {
        $devotee = Devotee::factory()->create(['name' => 'Lakshmi Iyer']);
        Sanctum::actingAs($devotee, guard: 'devotee');
        $id = $this->post('/api/v1/temples/gallery-temple/photos', ['photo' => UploadedFile::fake()->image('gate.jpg', 1200, 900), 'is_public' => $public ? '1' : '0', 'caption' => 'The eastern gopuram at dawn'])
            ->assertCreated()->json('data.id');

        return VisitPhoto::findOrFail($id);
    }

    public function test_only_an_approved_shared_photo_can_be_promoted(): void
    {
        $pending = $this->upload();
        try {
            PhotoPromotion::promote($pending, null);
            $this->fail('A pending photo must not be promoted.');
        } catch (InvalidArgumentException) {
        }

        $private = $this->upload(public: false);
        $private->update(['status' => PhotoModerationStatus::Approved]);
        $this->expectException(InvalidArgumentException::class);
        PhotoPromotion::promote($private, null);
    }

    public function test_a_promoted_photo_is_copied_credited_and_shown_on_the_temple_page(): void
    {
        $photo = $this->upload();
        $photo->update(['status' => PhotoModerationStatus::Approved]);
        $staff = User::factory()->create(['role' => UserRole::Editor, 'is_active' => true]);

        $gallery = PhotoPromotion::promote($photo->fresh(), $staff);

        $this->assertTrue(Storage::disk('gallery-test')->exists($gallery->path));
        $this->assertNotSame($photo->original_path, $gallery->path, 'a copy, not a reference to the devotee\'s file');
        $this->assertSame('Lakshmi Iyer', $gallery->credit);
        $this->assertTrue($gallery->isDevoteePhoto());

        $this->getJson('/api/v1/temples/gallery-temple')->assertOk()
            ->assertJsonPath('data.photos.0.is_devotee_photo', true)
            ->assertJsonPath('data.photos.0.devotee.name', 'Lakshmi I.')
            ->assertJsonPath('data.photos.0.caption', 'The eastern gopuram at dawn');

        $this->getJson('/api/v1/me/photos')->assertJsonPath('data.0.is_in_temple_gallery', true);

        // Twice is refused.
        $this->expectException(InvalidArgumentException::class);
        PhotoPromotion::promote($photo->fresh(), $staff);
    }

    public function test_the_devotee_deleting_their_photo_leaves_the_gallery_copy(): void
    {
        $photo = $this->upload();
        $photo->update(['status' => PhotoModerationStatus::Approved]);
        $gallery = PhotoPromotion::promote($photo->fresh(), null);

        $this->deleteJson("/api/v1/me/photos/{$photo->id}")->assertOk();

        $this->assertNotNull($gallery->fresh());
        $this->assertNull($gallery->fresh()->visit_photo_id);
        $this->assertTrue(Storage::disk('gallery-test')->exists($gallery->path));
    }

    public function test_the_temple_can_object_from_its_portal_which_takes_the_photo_down(): void
    {
        $photo = $this->upload();
        $photo->update(['status' => PhotoModerationStatus::Approved]);
        $gallery = PhotoPromotion::promote($photo->fresh(), null);

        $admin = User::factory()->create(['role' => UserRole::TempleAdmin, 'is_active' => true]);
        TempleUser::create(['temple_id' => $this->temple->id, 'user_id' => $admin->id, 'requested_at' => now(), 'approved_at' => now()]);
        $this->actingAs($admin, 'web');

        Livewire::test(\App\Filament\Resources\Temples\RelationManagers\PhotosRelationManager::class, ['ownerRecord' => $this->temple, 'pageClass' => \App\Filament\Temple\Resources\MyTemples\Pages\EditMyTemple::class])
            ->assertTableActionVisible('object', $gallery)
            ->callTableAction('object', $gallery, ['reason' => 'Photography inside the sanctum is not permitted.']);

        $gallery->refresh();
        $this->assertFalse($gallery->is_published);
        $this->assertTrue($gallery->templeObjected());
        $this->getJson('/api/v1/temples/gallery-temple')->assertOk()->assertJsonCount(0, 'data.photos');

        // The temple's own uploads carry no objection button.
        $own = TemplePhoto::create(['temple_id' => $this->temple->id, 'path' => 'temples/x/own.jpg', 'disk' => 'gallery-test']);
        Livewire::test(\App\Filament\Resources\Temples\RelationManagers\PhotosRelationManager::class, ['ownerRecord' => $this->temple, 'pageClass' => \App\Filament\Temple\Resources\MyTemples\Pages\EditMyTemple::class])
            ->assertTableActionHidden('object', $own);
    }

    public function test_staff_promote_from_the_moderation_queue(): void
    {
        $photo = $this->upload();
        $photo->update(['status' => PhotoModerationStatus::Approved]);

        $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin, 'is_active' => true]), 'web');
        Livewire::test(\App\Filament\Resources\VisitPhotos\Pages\ListVisitPhotos::class)
            ->assertTableActionVisible('promote', $photo)
            ->callTableAction('promote', $photo)
            ->assertTableActionHidden('promote', $photo->fresh());

        $this->assertSame(1, TemplePhoto::query()->whereNotNull('visit_photo_id')->count());
    }
}
