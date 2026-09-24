<?php

namespace Tests\Feature\Passport;

use App\Enums\TempleStatus;
use App\Models\Devotee;
use App\Models\DevoteeVisit;
use App\Models\Temple;
use App\Models\VisitPhoto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Memory photos: up to three per visit, alongside the one in the passport,
 * seen only by the devotee who took them.
 */
class MemoryPhotoTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_visit_keeps_three_private_memory_photos_outside_moderation(): void
    {
        Storage::fake(config('filesystems.media'));
        $temple = Temple::create(['name' => 'Meenakshi Amman Temple', 'status' => TempleStatus::Published]);
        $devotee = Devotee::factory()->create();
        $visit = DevoteeVisit::create(['devotee_id' => $devotee->id, 'temple_id' => $temple->id, 'visited_on' => '2026-03-01']);
        Sanctum::actingAs($devotee, guard: 'devotee');

        foreach (range(1, 3) as $n) {
            $this->post("/api/v1/temples/{$temple->slug}/photos", [
                'photo' => UploadedFile::fake()->image("m{$n}.jpg"),
                'visit_id' => $visit->id,
                'kind' => 'memory',
                'is_public' => '1',
            ], ['Accept' => 'application/json'])
                ->assertCreated()
                ->assertJsonPath('data.kind', 'memory')
                ->assertJsonPath('data.is_public', false);
        }

        $this->post("/api/v1/temples/{$temple->slug}/photos", [
            'photo' => UploadedFile::fake()->image('m4.jpg'),
            'visit_id' => $visit->id,
            'kind' => 'memory',
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('photo');

        // The passport photo is separate and still allowed.
        $this->post("/api/v1/temples/{$temple->slug}/photos", [
            'photo' => UploadedFile::fake()->image('stamp.jpg'),
            'visit_id' => $visit->id,
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.kind', 'stamp');

        $this->assertSame(1, VisitPhoto::query()->awaitingModeration()->count());
        $this->assertSame(3, VisitPhoto::query()->memories()->count());
    }

    public function test_a_memory_photo_needs_its_visit(): void
    {
        Storage::fake(config('filesystems.media'));
        $temple = Temple::create(['name' => 'Some Temple', 'status' => TempleStatus::Published]);
        Sanctum::actingAs(Devotee::factory()->create(), guard: 'devotee');

        $this->post("/api/v1/temples/{$temple->slug}/photos", [
            'photo' => UploadedFile::fake()->image('m.jpg'),
            'kind' => 'memory',
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('visit_id');
    }
}
