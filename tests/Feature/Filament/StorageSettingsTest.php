<?php

namespace Tests\Feature\Filament;

use App\Enums\UserRole;
use App\Filament\Pages\StorageSettings;
use App\Models\Setting;
use App\Models\User;
use App\Support\MediaStorage;
use App\Support\StorageHealth;
use App\Support\UploadRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Storage screen.
 *
 * It exists because "the images do not show" has half a dozen causes that are
 * indistinguishable from any other page, and working through them meant SSH.
 * What these tests protect is that each cause is reported separately and
 * honestly — a page that says everything is fine while the link is missing is
 * worse than no page at all.
 */
class StorageSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function superAdmin(): User
    {
        return User::factory()->create(['role' => UserRole::SuperAdmin, 'is_active' => true]);
    }

    public function test_it_renders(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(StorageSettings::class)->assertOk();
    }

    public function test_an_editor_cannot_open_it(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Editor, 'is_active' => true]));

        $this->assertFalse(StorageSettings::canAccess());
    }

    public function test_a_super_admin_can_open_it(): void
    {
        $this->actingAs($this->superAdmin());

        $this->assertTrue(StorageSettings::canAccess());
    }

    /**
     * The check that would be worth removing the page over: reporting health
     * while the link is broken sends somebody looking somewhere else.
     */
    public function test_a_missing_storage_link_is_reported_as_a_problem(): void
    {
        Config::set('filesystems.media', 'public');

        $link = StorageHealth::linkPath();
        $moved = $link.'.moved-for-test';
        $wasThere = file_exists($link) || is_link($link);

        if ($wasThere) {
            rename($link, $moved);
        }

        try {
            $this->actingAs($this->superAdmin());

            $rows = collect(Livewire::test(StorageSettings::class)->instance()->checks())
                ->keyBy('label');

            $this->assertFalse($rows['public/storage link']['ok']);
            $this->assertSame('Missing', $rows['public/storage link']['value']);
        } finally {
            if ($wasThere) {
                rename($moved, $link);
            }
        }
    }

    public function test_the_probe_writes_reads_and_cleans_up_after_itself(): void
    {
        Storage::fake('public');
        Config::set('filesystems.media', 'public');

        $result = StorageHealth::probeDisk();

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertEmpty(Storage::disk('public')->allFiles());
    }

    /**
     * Credentials must never reach the page. Somebody screen-sharing this
     * screen to ask for help should not be handing over their secret key.
     */
    public function test_spaces_secrets_are_never_shown(): void
    {
        Config::set('filesystems.media', 'spaces');
        Config::set('filesystems.disks.spaces.key', 'AKIAVERYSECRETKEY');
        Config::set('filesystems.disks.spaces.secret', 'the-secret-itself');

        $this->actingAs($this->superAdmin());

        $rendered = json_encode(Livewire::test(StorageSettings::class)->instance()->checks());

        $this->assertStringNotContainsString('AKIAVERYSECRETKEY', $rendered);
        $this->assertStringNotContainsString('the-secret-itself', $rendered);
    }

    public function test_missing_spaces_credentials_are_named(): void
    {
        Config::set('filesystems.media', 'spaces');
        Config::set('filesystems.disks.spaces.key', 'set');
        Config::set('filesystems.disks.spaces.secret', null);

        $this->actingAs($this->superAdmin());

        $rows = collect(Livewire::test(StorageSettings::class)->instance()->checks())
            ->keyBy('label');

        $this->assertFalse($rows['Spaces credentials']['ok']);
        $this->assertStringContainsString('secret', $rows['Spaces credentials']['value']);
    }

    /**
     * The failure this page exists to explain.
     *
     * A 2 MB upload_max_filesize is what shared plans ship, and a 50 MB form
     * on top of it fails before any application code runs: empty $_FILES, no
     * useful log line, an upload that appears to do nothing. The table must
     * print what will actually go through, not what the form was set to.
     */
    public function test_a_limit_the_server_will_not_honour_is_shown_as_the_server_s(): void
    {
        $this->actingAs($this->superAdmin());

        $rules = collect(Livewire::test(StorageSettings::class)->instance()->uploadRules())
            ->keyBy('label');

        $serverLimit = StorageHealth::phpUploadLimitKb();

        if ($serverLimit === null || $serverLimit >= UploadRules::maxKbFor('mantra_recording')) {
            $this->markTestSkipped('This PHP accepts uploads as large as any form allows.');
        }

        $this->assertTrue($rules['Mantra recordings']['capped']);
        $this->assertSame(UploadRules::readableSize($serverLimit), $rules['Mantra recordings']['max']);
        $this->assertSame('50 MB', $rules['Mantra recordings']['capped_from']);
    }

    /**
     * php.ini writes sizes as "8M", not as a number, and reading one wrong is
     * the difference between a reassuring green row and the truth.
     */
    public function test_php_size_strings_are_read_correctly(): void
    {
        $this->assertSame(2048, StorageHealth::iniToKilobytes('2M'));
        $this->assertSame(8192, StorageHealth::iniToKilobytes('8M'));
        $this->assertSame(512, StorageHealth::iniToKilobytes('512K'));
        $this->assertSame(1048576, StorageHealth::iniToKilobytes('1G'));
        $this->assertSame(2, StorageHealth::iniToKilobytes('2048'));

        // -1, 0 and an unset value all mean "no limit", not "nothing allowed".
        $this->assertNull(StorageHealth::iniToKilobytes('-1'));
        $this->assertNull(StorageHealth::iniToKilobytes('0'));
        $this->assertNull(StorageHealth::iniToKilobytes(''));
        $this->assertNull(StorageHealth::iniToKilobytes(false));
    }

    /**
     * The site fetching itself deadlocks on a server with one worker, which is
     * a busy server rather than a broken disk. Reporting it as a fault sends
     * somebody to fix the one thing that is working.
     */
    public function test_the_site_failing_to_reach_itself_is_not_reported_as_a_fault(): void
    {
        Storage::fake('public');
        Config::set('filesystems.media', 'public');
        Storage::disk('public')->put('temples/1/darshan.jpg', 'the bytes');

        Http::fake(fn () => throw new ConnectionException('timed out'));

        $this->actingAs($this->superAdmin());

        $page = Livewire::test(StorageSettings::class)->call('mountAction', 'check');

        $this->assertNull($page->get('reachability')['ok']);
        $this->assertStringNotContainsString('timed out', $page->get('reachability')['message']);
    }

    // --- Switching where uploads go ---

    public function test_the_switch_starts_on_whatever_is_in_use(): void
    {
        Config::set('filesystems.media', 'public');
        $this->actingAs($this->superAdmin());

        Livewire::test(StorageSettings::class)
            ->assertSet('data.media_disk', 'public');
    }

    /**
     * The refusal that makes the switch safe to offer at all. Saving
     * credentials that do not connect would send every later upload into a
     * bucket that rejects it, and an upload failing server-side looks to the
     * person uploading like a slow form.
     */
    public function test_saving_spaces_credentials_that_do_not_work_changes_nothing(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(StorageSettings::class)
            ->fillForm([
                'media_disk' => 'spaces',
                'spaces_key' => 'DO00KEY',
                'spaces_secret' => 'nope',
                'spaces_bucket' => 'temple-media',
                'spaces_region' => 'blr1',
                'spaces_endpoint' => 'https://blr1.digitaloceanspaces.invalid',
                'spaces_cdn_endpoint' => '',
            ])
            ->call('save');

        $this->assertNull(Setting::get('media_disk'));
        $this->assertSame('public', config('filesystems.media'));
    }

    /** The one field that must never reach the page, on any render. */
    public function test_the_stored_secret_is_never_rendered(): void
    {
        MediaStorage::save([
            'media_disk' => 'public',
            'spaces_key' => 'DO00KEY',
            'spaces_secret' => 'a-secret-worth-keeping',
            'spaces_bucket' => 'temple-media',
            'spaces_region' => 'blr1',
            'spaces_endpoint' => 'https://blr1.digitaloceanspaces.com',
            'spaces_cdn_endpoint' => '',
        ]);

        $this->actingAs($this->superAdmin());

        Livewire::test(StorageSettings::class)
            ->assertDontSee('a-secret-worth-keeping')
            ->assertSet('data.spaces_secret', null);
    }

    public function test_the_page_lists_every_kind_of_upload(): void
    {
        $this->actingAs($this->superAdmin());

        $listed = Livewire::test(StorageSettings::class)->instance()->uploadRules();

        $this->assertCount(count(UploadRules::all()), $listed);

        foreach ($listed as $rule) {
            $this->assertNotSame('', $rule['types']);
            $this->assertNotSame('', $rule['max']);
        }
    }
}
