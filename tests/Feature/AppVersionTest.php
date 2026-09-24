<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\AppVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** The running version, so a deploy that did not take can be seen. */
class AppVersionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        AppVersion::flush();
        parent::tearDown();
    }

    public function test_the_version_comes_from_the_version_file(): void
    {
        AppVersion::flush();

        $this->assertSame(trim(File::get(base_path('VERSION'))), AppVersion::number());
        $this->assertStringStartsWith('v'.AppVersion::number(), AppVersion::label());
    }

    public function test_the_commit_is_read_from_git_including_packed_refs(): void
    {
        $git = storage_path('framework/testing/fake-git');
        File::deleteDirectory($git);
        File::ensureDirectoryExists($git.'/refs/heads');
        File::put($git.'/HEAD', "ref: refs/heads/main\n");
        File::put($git.'/packed-refs', "# pack-refs\n0713cf5aaaabbbbccccddddeeeeffff000011112 refs/heads/main\n");

        $read = (new \ReflectionClass(AppVersion::class))->getMethod('gitCommit');
        $this->assertSame('0713cf5', $read->invoke(null, $git));

        File::put($git.'/refs/heads/main', "627c9ac0000000000000000000000000000000aa\n");
        $this->assertSame('627c9ac', $read->invoke(null, $git));

        $this->assertNull($read->invoke(null, $git.'-missing'));
        File::deleteDirectory($git);
    }

    public function test_both_panels_show_it(): void
    {
        $this->get('/admin/login')->assertOk()->assertSee(AppVersion::label());
        $this->get('/temple/login')->assertOk()->assertSee(AppVersion::label());

        $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin, 'is_active' => true]))
            ->get('/admin')->assertOk()->assertSee(AppVersion::label());
    }

    public function test_deploy_reports_it_and_clears_filaments_page_cache(): void
    {
        File::ensureDirectoryExists(base_path('bootstrap/cache/filament/panels'));
        File::put(base_path('bootstrap/cache/filament/panels/admin.php'), '<?php return [];');

        $this->artisan('app:deploy', ['--force' => true])
            ->expectsOutputToContain('Code on this server: '.AppVersion::label())
            ->assertSuccessful();

        $this->assertDirectoryDoesNotExist(base_path('bootstrap/cache/filament'));
    }
}
