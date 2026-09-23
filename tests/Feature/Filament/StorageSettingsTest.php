<?php

namespace Tests\Feature\Filament;

use App\Enums\UserRole;
use App\Filament\Pages\StorageSettings;
use App\Models\User;
use App\Support\StorageHealth;
use App\Support\UploadRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timed out'));

        $this->actingAs($this->superAdmin());

        $page = Livewire::test(StorageSettings::class)->call('mountAction', 'check');

        $this->assertNull($page->get('reachability')['ok']);
        $this->assertStringNotContainsString('timed out', $page->get('reachability')['message']);
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
