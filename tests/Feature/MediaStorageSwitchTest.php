<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Support\MediaStorage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Switching uploads between this server and DigitalOcean Spaces, from the
 * admin panel rather than from .env over SSH.
 *
 * The two rules worth a test each: a switch that has not been proven never
 * takes effect, and a switch never moves a file.
 */
class MediaStorageSwitchTest extends TestCase
{
    use RefreshDatabase;

    /** Six values that will fail to connect, which is what most of these want. */
    protected function credentials(array $overrides = []): array
    {
        return array_merge([
            'media_disk' => MediaStorage::SPACES_DISK,
            'spaces_key' => 'DO00KEY',
            'spaces_secret' => 'the-secret',
            'spaces_region' => 'blr1',
            'spaces_bucket' => 'temple-media',
            'spaces_endpoint' => 'https://blr1.digitaloceanspaces.invalid',
            'spaces_cdn_endpoint' => '',
        ], $overrides);
    }

    // --- Nothing switches until it is proven ---

    /**
     * The failure this whole design exists to prevent.
     *
     * Saving credentials that do not work would send every subsequent upload
     * into a bucket that rejects it — silently, because an upload failing
     * server-side looks to the person uploading like a slow form.
     */
    public function test_credentials_that_do_not_work_are_not_switched_to(): void
    {
        $result = MediaStorage::save($this->credentials());

        $this->assertFalse($result['ok']);
        $this->assertSame(MediaStorage::LOCAL_DISK, config('filesystems.media'));
        $this->assertNull(Setting::get('media_disk'));
    }

    public function test_a_refusal_says_uploads_are_still_working(): void
    {
        $result = MediaStorage::save($this->credentials());

        $this->assertStringContainsString('still going to this server', $result['message']);
    }

    public function test_switching_back_to_this_server_needs_no_connection(): void
    {
        $result = MediaStorage::save(['media_disk' => MediaStorage::LOCAL_DISK]);

        $this->assertTrue($result['ok']);
        $this->assertSame(MediaStorage::LOCAL_DISK, Setting::get('media_disk'));
    }

    public function test_incomplete_credentials_are_refused_before_any_request(): void
    {
        $result = MediaStorage::test($this->credentials(['spaces_bucket' => '']));

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Fill in', $result['message']);
    }

    /**
     * The other half of the rule, and the half a refusal test cannot show:
     * credentials that *do* work take effect, once.
     *
     * The bucket is a local fake here. The point under test is the decision —
     * prove, then save — not the S3 wire protocol, which is the SDK's job.
     */
    public function test_credentials_that_work_are_switched_to(): void
    {
        $this->fakeBucketThatWorks();

        $result = MediaStorage::save($this->credentials());

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame(MediaStorage::SPACES_DISK, Setting::get('media_disk'));
        $this->assertSame(MediaStorage::SPACES_DISK, config('filesystems.media'));
    }

    public function test_a_successful_switch_says_older_files_are_safe(): void
    {
        $this->fakeBucketThatWorks();

        $result = MediaStorage::save($this->credentials());

        $this->assertStringContainsString('stay where they are', $result['message']);
    }

    /** The connection test leaves nothing behind in the bucket. */
    public function test_the_connection_test_cleans_up_after_itself(): void
    {
        $bucket = $this->fakeBucketThatWorks();

        MediaStorage::test($this->credentials());

        $this->assertEmpty($bucket->allFiles());
    }

    /**
     * Swaps the S3 disk MediaStorage would build for a local fake, so the
     * decision can be tested without a DigitalOcean account.
     */
    protected function fakeBucketThatWorks(): Filesystem
    {
        $bucket = Storage::fake('test-bucket');

        $manager = \Mockery::mock(FilesystemManager::class);
        $manager->shouldReceive('build')->andReturn($bucket);

        $this->swap(FilesystemManager::class, $manager);

        return $bucket;
    }

    // --- The secret ---

    public function test_the_secret_is_encrypted_at_rest(): void
    {
        MediaStorage::save([...$this->credentials(), 'media_disk' => MediaStorage::LOCAL_DISK]);

        $stored = Setting::get('spaces_secret');

        $this->assertNotSame('the-secret', $stored);
        $this->assertSame('the-secret', Crypt::decryptString($stored));
    }

    /**
     * The form cannot show the secret, so a save from it arrives with that
     * field blank. Treating blank as "clear it" would wipe the secret every
     * time somebody corrected a typo in the bucket name.
     */
    public function test_saving_with_a_blank_secret_keeps_the_stored_one(): void
    {
        MediaStorage::save([...$this->credentials(), 'media_disk' => MediaStorage::LOCAL_DISK]);

        MediaStorage::save([
            ...$this->credentials(['spaces_secret' => '', 'spaces_bucket' => 'renamed']),
            'media_disk' => MediaStorage::LOCAL_DISK,
        ]);

        $this->assertSame('the-secret', Crypt::decryptString(Setting::get('spaces_secret')));
        $this->assertSame('renamed', Setting::get('spaces_bucket'));
    }

    public function test_the_secret_is_never_among_the_values_the_form_shows(): void
    {
        MediaStorage::save([...$this->credentials(), 'media_disk' => MediaStorage::LOCAL_DISK]);

        $this->assertArrayNotHasKey('spaces_secret', MediaStorage::formValues());
        $this->assertStringNotContainsString('the-secret', json_encode(MediaStorage::formValues()));
    }

    /**
     * After APP_KEY is rotated the stored secret cannot be decrypted. That
     * must read as "Spaces is not configured" — keeping uploads local — not as
     * a 500 on every page, which is what an uncaught DecryptException would be.
     */
    public function test_a_secret_that_cannot_be_decrypted_does_not_break_the_site(): void
    {
        Setting::set('spaces_secret', 'not-something-this-key-can-decrypt', 'encrypted');
        Setting::set('media_disk', MediaStorage::SPACES_DISK);
        Setting::set('spaces_key', 'DO00KEY');
        Setting::set('spaces_bucket', 'temple-media');
        Setting::set('spaces_endpoint', 'https://blr1.digitaloceanspaces.com');

        MediaStorage::apply();

        $this->assertSame(MediaStorage::LOCAL_DISK, config('filesystems.media'));
    }

    // --- Applying settings over config ---

    public function test_a_stored_disk_overrides_the_environment(): void
    {
        Config::set('filesystems.media', MediaStorage::SPACES_DISK);

        Setting::set('media_disk', MediaStorage::LOCAL_DISK);
        MediaStorage::apply();

        $this->assertSame(MediaStorage::LOCAL_DISK, config('filesystems.media'));
    }

    public function test_no_stored_disk_leaves_the_environment_alone(): void
    {
        Config::set('filesystems.media', MediaStorage::SPACES_DISK);

        MediaStorage::apply();

        $this->assertSame(MediaStorage::SPACES_DISK, config('filesystems.media'));
    }

    /**
     * Half-filled Spaces must not take effect. Sending uploads at a bucket
     * with no secret fails every one of them, and staying local is the
     * strictly better failure.
     */
    public function test_spaces_selected_but_not_filled_in_stays_local(): void
    {
        Setting::set('media_disk', MediaStorage::SPACES_DISK);
        Setting::set('spaces_key', 'DO00KEY');

        Config::set('filesystems.disks.spaces.secret', null);
        Config::set('filesystems.disks.spaces.bucket', null);

        MediaStorage::apply();

        $this->assertSame(MediaStorage::LOCAL_DISK, config('filesystems.media'));
    }

    public function test_stored_credentials_reach_the_disk_configuration(): void
    {
        MediaStorage::save([...$this->credentials(), 'media_disk' => MediaStorage::LOCAL_DISK]);

        MediaStorage::apply();

        $this->assertSame('DO00KEY', config('filesystems.disks.spaces.key'));
        $this->assertSame('the-secret', config('filesystems.disks.spaces.secret'));
        $this->assertSame('temple-media', config('filesystems.disks.spaces.bucket'));
    }

    /** Without a CDN endpoint, images come off the bucket rather than nowhere. */
    public function test_a_missing_cdn_endpoint_falls_back_to_the_bucket(): void
    {
        MediaStorage::save([
            ...$this->credentials(['spaces_cdn_endpoint' => '']),
            'media_disk' => MediaStorage::LOCAL_DISK,
        ]);

        MediaStorage::apply();

        $this->assertSame(
            config('filesystems.disks.spaces.endpoint'),
            config('filesystems.disks.spaces.url'),
        );
    }

    // --- Error messages worth reading ---

    public function test_sdk_errors_are_translated_into_something_actionable(): void
    {
        $result = MediaStorage::test($this->credentials());

        $this->assertFalse($result['ok']);
        // Whatever the SDK said, it must not be a wall of XML and request ids.
        $this->assertLessThan(250, strlen($result['message']));
        $this->assertStringNotContainsString('<?xml', $result['message']);
    }
}
