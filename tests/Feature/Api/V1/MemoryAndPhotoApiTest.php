<?php

namespace Tests\Feature\Api\V1;

use App\Enums\PhotoModerationStatus;
use App\Enums\TempleStatus;
use App\Models\Devotee;
use App\Models\DevoteeMemory;
use App\Models\DevoteeVisit;
use App\Models\Temple;
use App\Models\VisitPhoto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Memories and Photo Stamp, which share one rule: nothing a devotee creates
 * reaches anyone else until they have said so AND a moderator has agreed.
 */
class MemoryAndPhotoApiTest extends TestCase
{
    use RefreshDatabase;

    protected function temple(string $name = 'Kashi Vishwanath'): Temple
    {
        return Temple::create(['name' => $name, 'status' => TempleStatus::Published]);
    }

    protected function signIn(): Devotee
    {
        $devotee = Devotee::factory()->create();
        Sanctum::actingAs($devotee, guard: 'devotee');

        return $devotee;
    }

    // --- Memories ---

    public function test_a_memory_is_private_unless_the_devotee_says_otherwise(): void
    {
        $this->signIn();

        $response = $this->postJson('/api/v1/me/memories', [
            'body' => 'I prayed for my mother.',
        ])->assertCreated();

        $this->assertTrue($response->json('data.is_private'));
    }

    /**
     * The failure that matters: a PATCH that leaves out is_private must not
     * publish a private memory. Someone editing a typo is not consenting to
     * share it.
     */
    public function test_editing_a_memory_without_sending_the_flag_leaves_it_private(): void
    {
        $devotee = $this->signIn();

        $memory = DevoteeMemory::create([
            'devotee_id' => $devotee->id,
            'body' => 'Private thoughts.',
            'is_private' => true,
        ]);

        $this->patchJson("/api/v1/me/memories/{$memory->id}", ['title' => 'A title'])
            ->assertOk();

        $this->assertTrue($memory->fresh()->is_private);
    }

    public function test_a_memory_can_be_shared_deliberately(): void
    {
        $this->signIn();

        $id = $this->postJson('/api/v1/me/memories', ['body' => 'Sharing this.'])->json('data.id');

        $this->patchJson("/api/v1/me/memories/{$id}", ['is_private' => false])->assertOk();

        $this->assertFalse(DevoteeMemory::findOrFail($id)->is_private);
    }

    public function test_a_memory_cannot_be_attached_to_another_devotee_s_visit(): void
    {
        $temple = $this->temple();
        $stranger = Devotee::factory()->create();

        $theirVisit = DevoteeVisit::create([
            'devotee_id' => $stranger->id,
            'temple_id' => $temple->id,
            'visited_on' => '2026-01-01',
        ]);

        $this->signIn();

        $this->postJson('/api/v1/me/memories', [
            'body' => 'Not my visit.',
            'devotee_visit_id' => $theirVisit->id,
        ])->assertStatus(422)->assertJsonValidationErrors('devotee_visit_id');
    }

    public function test_one_devotee_cannot_read_or_edit_another_s_memories(): void
    {
        $stranger = Devotee::factory()->create();
        $theirs = DevoteeMemory::create(['devotee_id' => $stranger->id, 'body' => 'Theirs.']);

        $this->signIn();

        $this->getJson('/api/v1/me/memories')->assertOk()->assertJsonCount(0, 'data');
        $this->patchJson("/api/v1/me/memories/{$theirs->id}", ['body' => 'Changed'])->assertNotFound();
        $this->deleteJson("/api/v1/me/memories/{$theirs->id}")->assertNotFound();

        $this->assertSame('Theirs.', $theirs->fresh()->body);
    }

    public function test_a_memory_cannot_be_dated_in_the_future(): void
    {
        $this->signIn();

        $this->postJson('/api/v1/me/memories', [
            'body' => 'Not yet.',
            'happened_on' => now()->addDay()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors('happened_on');
    }

    // --- Photo Stamp ---

    public function test_a_photo_upload_keeps_the_original_and_the_stamp_apart(): void
    {
        Storage::fake(config('filesystems.media'));

        $temple = $this->temple();
        $this->signIn();

        $response = $this->postJson("/api/v1/temples/{$temple->slug}/photos", [
            'photo' => UploadedFile::fake()->image('darshan.jpg', 1200, 900),
            'stamp' => UploadedFile::fake()->image('stamp.jpg', 1080, 1080),
            'caption' => 'First darshan.',
        ])->assertCreated();

        $photo = VisitPhoto::firstOrFail();

        $this->assertNotSame($photo->original_path, $photo->stamp_path);
        Storage::disk(config('filesystems.media'))->assertExists($photo->original_path);
        Storage::disk(config('filesystems.media'))->assertExists($photo->stamp_path);
        $this->assertTrue($response->json('data.has_stamp'));
    }

    /** A devotee who wants the photo kept but not stamped is not doing it wrong. */
    public function test_a_photo_may_be_uploaded_without_a_stamp(): void
    {
        Storage::fake(config('filesystems.media'));

        $temple = $this->temple();
        $this->signIn();

        $response = $this->postJson("/api/v1/temples/{$temple->slug}/photos", [
            'photo' => UploadedFile::fake()->image('darshan.jpg'),
        ])->assertCreated();

        $this->assertFalse($response->json('data.has_stamp'));
        // The card falls back to the original rather than returning nothing.
        $this->assertNotNull($response->json('data.stamp_url'));
    }

    public function test_an_uploaded_photo_waits_for_moderation(): void
    {
        Storage::fake(config('filesystems.media'));

        $temple = $this->temple();
        $this->signIn();

        $response = $this->postJson("/api/v1/temples/{$temple->slug}/photos", [
            'photo' => UploadedFile::fake()->image('darshan.jpg'),
            // Even having asked for it to be public.
            'is_public' => true,
        ])->assertCreated();

        $this->assertSame(PhotoModerationStatus::Pending->value, $response->json('data.status.value'));
        $this->assertTrue($response->json('data.is_public'));
        $this->assertFalse($response->json('data.is_visible_to_others'));
    }

    /** Approval alone is not publication; the devotee's own choice still stands. */
    public function test_an_approved_but_private_photo_stays_hidden(): void
    {
        $temple = $this->temple();
        $devotee = Devotee::factory()->create();

        $hidden = VisitPhoto::create([
            'devotee_id' => $devotee->id, 'temple_id' => $temple->id,
            'original_path' => 'a.jpg',
            'status' => PhotoModerationStatus::Approved, 'is_public' => false,
        ]);

        $shared = VisitPhoto::create([
            'devotee_id' => $devotee->id, 'temple_id' => $temple->id,
            'original_path' => 'b.jpg',
            'status' => PhotoModerationStatus::Approved, 'is_public' => true,
        ]);

        $this->assertFalse($hidden->isVisibleToOthers());
        $this->assertTrue($shared->isVisibleToOthers());

        $visible = VisitPhoto::query()->visibleToOthers()->pluck('id');

        $this->assertTrue($visible->contains($shared->id));
        $this->assertFalse($visible->contains($hidden->id));
    }

    public function test_a_photo_cannot_be_filed_under_another_devotee_s_visit(): void
    {
        Storage::fake(config('filesystems.media'));

        $temple = $this->temple();
        $stranger = Devotee::factory()->create();

        $theirVisit = DevoteeVisit::create([
            'devotee_id' => $stranger->id, 'temple_id' => $temple->id,
            'visited_on' => '2026-01-01',
        ]);

        $this->signIn();

        $this->postJson("/api/v1/temples/{$temple->slug}/photos", [
            'photo' => UploadedFile::fake()->image('darshan.jpg'),
            'visit_id' => $theirVisit->id,
        ])->assertNotFound();

        $this->assertDatabaseCount('visit_photos', 0);
    }

    public function test_only_images_are_accepted(): void
    {
        Storage::fake(config('filesystems.media'));

        $temple = $this->temple();
        $this->signIn();

        $this->postJson("/api/v1/temples/{$temple->slug}/photos", [
            'photo' => UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf'),
        ])->assertStatus(422)->assertJsonValidationErrors('photo');
    }

    public function test_a_guest_cannot_upload(): void
    {
        $temple = $this->temple();

        $this->postJson("/api/v1/temples/{$temple->slug}/photos", [])->assertUnauthorized();
    }
}
