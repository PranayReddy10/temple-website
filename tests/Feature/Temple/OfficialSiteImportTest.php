<?php

namespace Tests\Feature\Temple;

use App\Enums\UserRole;
use App\Filament\Resources\Temples\Pages\EditTemple;
use App\Filament\Resources\Temples\Pages\ListTemples;
use App\Models\Temple;
use App\Models\TemplePuja;
use App\Models\User;
use App\Support\OfficialSite\OfficialSiteImport;
use App\Support\OfficialSite\OfficialSiteReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A temple's own website, read for staff to review: facts are suggested,
 * nothing reaches the listing until someone ticks it.
 */
class OfficialSiteImportTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'https://www.swarnagiritemple.com';

    private function fixture(string $name): string
    {
        return file_get_contents(base_path('tests/Fixtures/official-site/'.$name));
    }

    private function fakeSite(): void
    {
        $html = ['Content-Type' => 'text/html; charset=utf-8'];
        Http::fake([
            self::SITE.'/darshan-timings' => Http::response($this->fixture('timings.html'), 200, $html),
            self::SITE.'/sevas.html' => Http::response($this->fixture('sevas.html'), 200, $html),
            self::SITE.'/contact-us' => Http::response('%PDF-1.4', 200, ['Content-Type' => 'application/pdf']),
            self::SITE.'*' => Http::response($this->fixture('home.html'), 200, $html),
            '*' => Http::response('', 404),
        ]);
    }

    private function staff(): User
    {
        return User::factory()->create(['role' => UserRole::SuperAdmin, 'is_active' => true]);
    }

    public function test_the_reader_finds_contact_timings_and_sevas(): void
    {
        $found = OfficialSiteReader::extract([
            self::SITE.'/' => $this->fixture('home.html'),
            self::SITE.'/darshan-timings' => $this->fixture('timings.html'),
            self::SITE.'/sevas.html' => $this->fixture('sevas.html'),
        ]);

        $this->assertContains('info@swarnagiritemple.com', $found['emails']);
        $this->assertNotEmpty($found['phones']);
        $this->assertSame('508116', $found['pincode']);
        $this->assertEqualsWithDelta(17.5098, $found['latitude'], 0.0001);
        $this->assertContains(['06:00', '13:00'], array_map(fn ($t) => [$t['opens_at'], $t['closes_at']], $found['timings']));

        $fees = collect($found['sevas'])->pluck('fee', 'name');
        $this->assertEquals(500, $fees['Abhishekam']);
        $this->assertEquals(1116, $fees['Kalyanotsavam']);
    }

    public function test_reading_keeps_the_findings_for_review_and_skips_non_html_pages(): void
    {
        $this->fakeSite();
        $temple = Temple::create(['name' => 'Swarnagiri', 'official_website' => self::SITE]);

        $found = OfficialSiteImport::read($temple);

        $this->assertArrayNotHasKey('error', $found);
        $this->assertNotContains(self::SITE.'/contact-us', $found['pages']);
        $temple->refresh();
        $this->assertNotNull($temple->official_import);
        $this->assertNull($temple->official_import_reviewed_at);
        // Nothing on the listing itself has changed.
        $this->assertNull($temple->contact_email);
        $this->assertSame(0, $temple->timings()->count());
    }

    public function test_an_unreachable_site_is_reported_not_stored(): void
    {
        Http::fake(['*' => Http::response('', 500)]);
        $temple = Temple::create(['name' => 'Offline', 'official_website' => 'https://offline.example']);

        $this->assertArrayHasKey('error', OfficialSiteImport::read($temple));
        $this->assertNull($temple->refresh()->official_import);
    }

    public function test_staff_take_only_what_they_tick(): void
    {
        $this->fakeSite();
        $this->actingAs($this->staff());
        $temple = Temple::create(['name' => 'Swarnagiri', 'official_website' => self::SITE, 'contact_phone' => '040 1111 2222']);
        TemplePuja::create(['temple_id' => $temple->id, 'kind' => 'seva', 'name' => 'Archana', 'fee_amount' => 20, 'is_free' => false]);
        OfficialSiteImport::read($temple);
        $found = $temple->refresh()->official_import;
        $abhishekam = collect($found['sevas'])->search(fn ($s) => $s['name'] === 'Abhishekam');
        $archana = collect($found['sevas'])->search(fn ($s) => $s['name'] === 'Archana');

        Livewire::test(EditTemple::class, ['record' => $temple->getRouteKey()])
            ->callAction('reviewOfficialSite', data: [
                'use_contact_phone' => false,
                'use_contact_email' => true,
                'use_pincode' => true,
                'timings' => [0, 1],
                'sevas' => [$abhishekam, $archana],
                'publish_sevas' => false,
            ])
            ->assertHasNoActionErrors();

        $temple->refresh();
        $this->assertSame('040 1111 2222', $temple->contact_phone, 'an unticked field stays as it was');
        $this->assertSame('info@swarnagiritemple.com', $temple->contact_email);
        $this->assertSame('508116', $temple->pincode);
        $this->assertSame(self::SITE, rtrim($temple->source_url, '/'));
        $this->assertNotNull($temple->official_import_reviewed_at);
        $this->assertSame(2, $temple->timings()->count());

        // A new seva arrives as a draft, with booking off; a known one gets the fee.
        $new = $temple->pujas()->where('name', 'Abhishekam')->firstOrFail();
        $this->assertFalse((bool) $new->is_published);
        $this->assertFalse((bool) $new->app_booking_enabled);
        $this->assertEquals(500, $new->fee_amount);
        $this->assertEquals(50, $temple->pujas()->where('name', 'Archana')->value('fee_amount'));
        $this->assertSame(2, $temple->pujas()->count());
    }

    public function test_the_list_shows_temples_waiting_for_review(): void
    {
        $this->actingAs($this->staff());
        $waiting = Temple::forceCreate(['name' => 'Waiting', 'official_import' => ['timings' => []], 'official_import_at' => now()]);
        $done = Temple::forceCreate(['name' => 'Done', 'official_import' => ['timings' => []], 'official_import_at' => now(), 'official_import_reviewed_at' => now()]);
        $never = Temple::create(['name' => 'Never read']);

        Livewire::test(ListTemples::class)
            ->filterTable('official_to_review')
            ->assertCanSeeTableRecords([$waiting])
            ->assertCanNotSeeTableRecords([$done, $never]);
    }

    public function test_the_command_reads_every_temple_with_a_website(): void
    {
        $this->fakeSite();
        $with = Temple::create(['name' => 'Swarnagiri', 'official_website' => self::SITE]);
        $without = Temple::create(['name' => 'No site']);
        $recent = Temple::forceCreate(['name' => 'Read last week', 'official_website' => self::SITE, 'official_import' => ['x' => 1], 'official_import_at' => now()->subWeek()]);

        $this->artisan('temples:read-official-sites')->assertSuccessful();

        $this->assertNotNull($with->refresh()->official_import);
        $this->assertNull($without->refresh()->official_import);
        $this->assertSame(['x' => 1], $recent->refresh()->official_import);
    }
}
