<?php

namespace Tests\Feature\Storage;

use App\Enums\TempleStatus;
use App\Models\Devotee;
use App\Models\Temple;
use App\Support\MediaStorage;
use App\Support\MoveMediaToSpaces;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Files already on the server are carried to Spaces, and nothing new from
 * the apps is left on the server once Spaces is on.
 */
class MoveMediaToSpacesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        Storage::fake('spaces');
        config([
            'filesystems.disks.spaces.key' => 'k', 'filesystems.disks.spaces.secret' => 's',
            'filesystems.disks.spaces.bucket' => 'b', 'filesystems.disks.spaces.endpoint' => 'https://blr1.digitaloceanspaces.com',
        ]);
    }

    public function test_older_files_move_to_spaces_and_leave_the_server(): void
    {
        $temple = Temple::create(['name' => 'Sri Rama Temple', 'slug' => 'sri-rama', 'status' => TempleStatus::Published, 'published_at' => now()]);
        Storage::disk('public')->put('temples/1/a.jpg', 'photo');
        Storage::disk('public')->put('temples/1/a-medium.jpg', 'medium');
        $photoId = DB::table('temple_photos')->insertGetId([
            'temple_id' => $temple->id, 'disk' => 'public', 'path' => 'temples/1/a.jpg', 'medium_path' => 'temples/1/a-medium.jpg',
            'category' => 'gallery', 'is_published' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $devotee = Devotee::factory()->create();
        Storage::disk('public')->put('avatars/1/me.jpg', 'me');
        DB::table('devotees')->where('id', $devotee->id)->update(['avatar_disk' => 'public', 'avatar_path' => 'avatars/1/me.jpg']);

        Storage::disk('local')->put('kyc/1/aadhaar.jpg', 'aadhaar');
        $account = $this->approvePayments($temple);
        DB::table('temple_payout_accounts')->where('id', $account->id)->update([
            'aadhaar_front_path' => 'kyc/1/aadhaar.jpg',
        ]);

        config(['filesystems.media' => 'spaces']);
        $this->assertSame(3, MoveMediaToSpaces::remainingTotal());

        $result = MoveMediaToSpaces::run();

        $this->assertSame(3, $result['moved']);
        $this->assertSame([], $result['failed']);
        $this->assertSame(0, $result['remaining']);

        $this->assertSame('spaces', DB::table('temple_photos')->where('id', $photoId)->value('disk'));
        $this->assertSame('spaces', DB::table('devotees')->where('id', $devotee->id)->value('avatar_disk'));
        Storage::disk('spaces')->assertExists(['temples/1/a.jpg', 'temples/1/a-medium.jpg', 'avatars/1/me.jpg', 'kyc/1/aadhaar.jpg']);
        Storage::disk('public')->assertMissing(['temples/1/a.jpg', 'temples/1/a-medium.jpg', 'avatars/1/me.jpg']);
        Storage::disk('local')->assertMissing('kyc/1/aadhaar.jpg');
        $this->assertSame('private', Storage::disk('spaces')->getVisibility('kyc/1/aadhaar.jpg'));
        $this->assertSame('public', Storage::disk('spaces')->getVisibility('temples/1/a.jpg'));

        // Moving the documents is not a change that needs approval again.
        $this->assertTrue($temple->fresh()->canCollectPayments());
    }

    public function test_nothing_moves_until_spaces_is_set_up(): void
    {
        config(['filesystems.disks.spaces.key' => null]);
        $result = MoveMediaToSpaces::run();

        $this->assertSame(0, $result['moved']);
        $this->assertNotEmpty($result['failed']);
    }

    public function test_verification_documents_go_privately_to_spaces_when_it_is_on(): void
    {
        config(['filesystems.media' => 'spaces']);
        $this->assertSame('spaces', MediaStorage::privateDisk());

        config(['filesystems.media' => 'public']);
        $this->assertSame('local', MediaStorage::privateDisk());
    }
}
