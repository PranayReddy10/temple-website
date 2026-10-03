<?php

namespace Tests\Feature\Temple;

use App\Enums\UserRole;
use App\Filament\Resources\Temples\Pages\EditTemple;
use App\Filament\Resources\Temples\Pages\ListTemples;
use App\Models\Temple;
use App\Models\TemplePuja;
use App\Models\TempleTiming;
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
            self::SITE.'/arjitha-sevas-list' => Http::response($this->fixture('seva-cards.html'), 200, $html),
            self::SITE.'/contact-us' => Http::response('%PDF-1.4', 200, ['Content-Type' => 'application/pdf']),
            self::SITE.'/sitemap.xml' => Http::response('', 404),
            self::SITE.'*' => Http::response($this->fixture('home.html'), 200, $html),
            '*' => Http::response('', 404),
        ]);
    }

    private function staff(): User
    {
        return User::factory()->create(['role' => UserRole::SuperAdmin, 'is_active' => true]);
    }

    /** @return array<string, array<string, mixed>> */
    private function byLabel(array $timings): array
    {
        return collect($timings)->mapWithKeys(fn ($t) => [$t['label'].'|'.($t['notes'] ?? '') => $t])->all();
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
        $this->assertContains(['Sarva Darshan', '06:00', '13:00'], array_map(fn ($t) => [$t['label'], $t['opens_at'], $t['closes_at']], $found['timings']));

        $fees = collect($found['sevas'])->pluck('fee', 'name');
        $this->assertEquals(500, $fees['Abhishekam']);
        $this->assertEquals(1116, $fees['Kalyanotsavam']);
        $this->assertFalse($fees->has('Donations to Hundi accepted through bank account'));
    }

    /** Morning and evening on one line, weekday and weekend, an aarti table. */
    public function test_a_line_with_several_timings_and_an_aarti_table(): void
    {
        $t = $this->byLabel(OfficialSiteReader::extract(['https://hk.example/' => $this->fixture('golden-temple.html')])['timings']);

        $this->assertSame(['07:15', '13:00'], [$t['Darshan – Morning|Weekdays (Mon–Fri)']['opens_at'], $t['Darshan – Morning|Weekdays (Mon–Fri)']['closes_at']]);
        $this->assertSame('20:45', $t['Darshan – Evening|Weekdays (Mon–Fri)']['closes_at']);
        $this->assertSame('21:00', $t['Darshan – Evening|Weekends (Sat–Sun)']['closes_at']);
        $this->assertSame(['04:30', '05:05', 'aarti'], [$t['Mangala Arati|']['opens_at'], $t['Mangala Arati|']['closes_at'], $t['Mangala Arati|']['kind']]);
        // A single time is a timing too.
        $this->assertSame(['19:00', null], [$t['Sandhya Arati|']['opens_at'], $t['Sandhya Arati|']['closes_at']]);
        // "(Sat & Sun 8:35 PM – 9:00 PM)" is the same aarti on other days.
        $this->assertSame('20:35', $t['Shayana Arati|Sat & Sun']['opens_at']);
        $this->assertSame('20:10', $t['Shayana Arati|']['opens_at']);
        $this->assertCount(11, $t);
    }

    /** A seva table row is one seva: its time and days stay with it, not split off as timings. */
    public function test_a_seva_table_and_ticket_cards_come_out_whole(): void
    {
        $found = OfficialSiteReader::extract(['https://s.example/sevas' => $this->fixture('seva-cards.html')]);
        $sevas = collect($found['sevas'])->keyBy('name');

        $this->assertSame(['Suprabhatha Seva', 'Archana', 'Abhishekam', 'Thomala Seva', 'Sri Vari Kalyanam', 'Seegra Darshan', 'Donor Darshan', 'Seva Darshan'], $sevas->keys()->all());
        $this->assertSame([500, '05:00'], [$sevas['Suprabhatha Seva']['fee'], $sevas['Suprabhatha Seva']['starts_at']]);
        $this->assertSame([119, 15, 'puja'], [$sevas['Archana']['fee'], $sevas['Archana']['duration_minutes'], $sevas['Archana']['kind']]);
        $this->assertSame([172, '07:00', 'Tue/Thu/Fri'], [$sevas['Abhishekam']['fee'], $sevas['Abhishekam']['starts_at'], $sevas['Abhishekam']['note']]);
        $this->assertSame([1500, 'Tue, Thu, Fri'], [$sevas['Thomala Seva']['fee'], $sevas['Thomala Seva']['note']]);
        $this->assertSame(51000, $sevas['Sri Vari Kalyanam']['fee']);
        $this->assertSame(50, $sevas['Seegra Darshan']['fee']);
        $this->assertSame(500, $sevas['Seva Darshan']['fee']);

        $labels = collect($found['timings'])->pluck('label')->all();
        $this->assertSame(['Temple – Morning', 'Temple – Evening', 'Temple closed'], $labels);
    }

    public function test_times_are_read_in_the_forms_temples_write_them(): void
    {
        $read = fn (string $line) => array_map(fn ($t) => [$t['opens_at'], $t['closes_at']], OfficialSiteReader::timingsIn($line));

        $this->assertSame([['18:00', '21:00']], $read('Darshan 6 - 9 PM'));
        $this->assertSame([['11:00', '13:00']], $read('Abhishekam 11 - 1 PM'));
        $this->assertSame([['12:00', null]], $read('Annadanam at 12 Noon'));
        $this->assertSame([['05:00', '21:00']], $read('Temple: 05:00 hrs to 21:00 hrs'));
        $this->assertSame([['06:00', '12:30'], ['16:00', '20:30']], $read('The temple is open from 6.00 a.m. to 12.30 p.m. and 4.00 p.m. to 8.30 p.m.'));
        $this->assertSame([['05:30', '13:00'], ['15:00', '20:30']], $read('Darshan: 5:30AM-1PM & 3PM-8:30PM'));
        // Not times.
        $this->assertSame([], $read('Established 2018-2023, parking for 10-12 buses'));
        $this->assertSame([], $read('Ph 040-2332 6789'));
        $this->assertSame([], $read('Date 12.10.2026'));

        $this->assertSame('17:30', OfficialSiteReader::fromTwelveHour('5:30 pm'));
        $this->assertSame('5:30 PM', OfficialSiteReader::twelveHour('17:30:00'));
        $this->assertSame('12:15 AM', OfficialSiteReader::twelveHour('00:15'));
    }

    public function test_reading_follows_the_sites_own_pages_and_the_ones_staff_add(): void
    {
        $this->fakeSite();
        $temple = Temple::create(['name' => 'Swarnagiri', 'official_website' => self::SITE]);

        $found = OfficialSiteImport::read($temple, null, [self::SITE.'/arjitha-sevas-list']);

        $this->assertArrayNotHasKey('error', $found);
        $pages = collect($found['pages'])->keyBy('url');
        $this->assertTrue($pages->has(self::SITE.'/darshan-timings'), 'linked from the home page');
        $this->assertTrue($pages->has(self::SITE.'/arjitha-sevas-list'), 'listed by staff');
        $this->assertFalse($pages->has(self::SITE.'/contact-us'), 'not a web page');
        $this->assertSame(8, $pages[self::SITE.'/arjitha-sevas-list']['sevas']);
        $this->assertSame(self::SITE.'/files/seva-list-2026.pdf', $found['documents'][0]['url']);

        $temple->refresh();
        $this->assertSame([self::SITE.'/arjitha-sevas-list'], $temple->official_site_pages);
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

        $found = OfficialSiteImport::read($temple);

        $this->assertStringContainsString('500', $found['error']);
        $this->assertNull($temple->refresh()->official_import);
    }

    public function test_staff_take_only_what_they_tick_as_they_corrected_it(): void
    {
        $this->fakeSite();
        $this->actingAs($this->staff());
        $temple = Temple::create(['name' => 'Swarnagiri', 'official_website' => self::SITE, 'contact_phone' => '040 1111 2222']);
        TemplePuja::create(['temple_id' => $temple->id, 'kind' => 'seva', 'name' => 'Archana', 'fee_amount' => 20, 'is_free' => false]);
        OfficialSiteImport::read($temple);

        $review = Livewire::test(EditTemple::class, ['record' => $temple->getRouteKey()])
            ->mountAction('reviewOfficialSite');
        // Shown in 12-hour time, ready to correct.
        $shown = collect($review->get('mountedActions.0.data.timings'))->pluck('opens_at')->all();
        $this->assertContains('5:30 AM', $shown);
        $this->assertContains('8:30 PM', $shown);

        $review->set('mountedActions.0.data.timings', [
            ['take' => true, 'label' => 'Suprabhatha Seva', 'kind' => 'special', 'opens_at' => '5:30 AM', 'closes_at' => '6:00 AM', 'notes' => null],
            ['take' => true, 'label' => 'Sarva Darshan', 'kind' => 'darshan', 'opens_at' => '6:00 AM', 'closes_at' => '1:30 PM', 'notes' => 'Corrected'],
            ['take' => false, 'label' => 'Ekantha Seva', 'kind' => 'special', 'opens_at' => '8:30 PM', 'closes_at' => '9:00 PM', 'notes' => null],
        ]);
        $review->set('mountedActions.0.data.sevas', [
            ['take' => true, 'name' => 'Abhishekam', 'kind' => 'puja', 'fee' => 500, 'starts_at' => '7:00 AM', 'note' => 'Tue/Thu/Fri'],
            ['take' => true, 'name' => 'Archana', 'kind' => 'puja', 'fee' => 50, 'starts_at' => null, 'note' => null],
            ['take' => false, 'name' => 'Kalyanotsavam', 'kind' => 'seva', 'fee' => 1116, 'starts_at' => null, 'note' => null],
        ]);

        $review->callMountedAction([
            'use_contact_phone' => false,
            'use_contact_email' => true,
            'use_pincode' => true,
            'publish_sevas' => false,
        ])
            ->assertHasNoFormErrors();

        $temple->refresh();
        $this->assertSame('040 1111 2222', $temple->contact_phone, 'an unticked field stays as it was');
        $this->assertSame('info@swarnagiritemple.com', $temple->contact_email);
        $this->assertSame('508116', $temple->pincode);
        $this->assertSame(self::SITE, rtrim($temple->source_url, '/'));
        $this->assertNotNull($temple->official_import_reviewed_at);

        $this->assertSame(['Suprabhatha Seva', 'Sarva Darshan'], $temple->timings()->orderBy('sort_order')->pluck('label')->all());
        $darshan = TempleTiming::where('label', 'Sarva Darshan')->first();
        $this->assertSame('6:00 AM – 1:30 PM', $darshan->window(), 'stored as corrected, shown in 12-hour time');
        $this->assertSame('Corrected', $darshan->notes);

        // A new seva arrives as a draft, with booking off; a known one gets the fee.
        $new = $temple->pujas()->where('name', 'Abhishekam')->firstOrFail();
        $this->assertFalse((bool) $new->is_published);
        $this->assertFalse((bool) $new->app_booking_enabled);
        $this->assertEquals(500, $new->fee_amount);
        $this->assertSame('07:00', substr((string) $new->starts_at, 0, 5));
        $this->assertSame('Tue/Thu/Fri', $new->schedule_note);
        $this->assertEquals(50, $temple->pujas()->where('name', 'Archana')->value('fee_amount'));
        $this->assertSame(2, $temple->pujas()->count());
    }

    public function test_a_time_staff_cannot_read_is_refused(): void
    {
        $this->fakeSite();
        $this->actingAs($this->staff());
        $temple = Temple::create(['name' => 'Swarnagiri', 'official_website' => self::SITE]);
        OfficialSiteImport::read($temple);

        Livewire::test(EditTemple::class, ['record' => $temple->getRouteKey()])
            ->callAction('reviewOfficialSite', data: [
                'timings' => [['take' => true, 'label' => 'Darshan', 'kind' => 'darshan', 'opens_at' => 'early morning', 'closes_at' => '1:00 PM']],
            ])
            ->assertHasActionErrors(['timings.0.opens_at']);

        $this->assertSame(0, $temple->timings()->count());
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

    public function test_the_command_reads_every_temple_with_a_website_and_its_saved_pages(): void
    {
        $this->fakeSite();
        $with = Temple::forceCreate(['name' => 'Swarnagiri', 'official_website' => self::SITE, 'official_site_pages' => [self::SITE.'/arjitha-sevas-list']]);
        $without = Temple::create(['name' => 'No site']);
        $recent = Temple::forceCreate(['name' => 'Read last week', 'official_website' => self::SITE, 'official_import' => ['x' => 1], 'official_import_at' => now()->subWeek()]);

        $this->artisan('temples:read-official-sites')->assertSuccessful();

        $this->assertContains('Seegra Darshan', array_column($with->refresh()->official_import['sevas'], 'name'));
        $this->assertNull($without->refresh()->official_import);
        $this->assertSame(['x' => 1], $recent->refresh()->official_import);
    }
}
