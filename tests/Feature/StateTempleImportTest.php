<?php

namespace Tests\Feature;

use App\Enums\TempleStatus;
use App\Enums\VerificationStatus;
use App\Models\State;
use App\Models\Temple;
use App\Models\TemplePuja;
use App\Models\User;
use App\Support\TempleAgent\TempleResearcher;
use Database\Seeders\DeitySeeder;
use Database\Seeders\StateSeeder;
use Database\Seeders\StateTempleDataSeeder;
use Database\Seeders\TelanganaTempleSeeder;
use Database\Seeders\TempleCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StateTempleImportTest extends TestCase
{
    use RefreshDatabase;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([StateSeeder::class, DeitySeeder::class, TempleCategorySeeder::class]);
        $this->file = tempnam(sys_get_temp_dir(), 'temples').'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);

        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function kanipakam(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Sri Varasiddhi Vinayaka Swamy Temple, Kanipakam', 'deity' => 'ganesha', 'famous' => true,
            'district' => 'Chittoor', 'city' => 'Kanipakam', 'address' => 'Kanipakam, Irala Mandal', 'pincode' => '517131',
            'lat' => 13.2794, 'lng' => 79.0350,
            'categories' => ['river-ghat-temple'],
            'aliases' => ['Kanipakam Temple' => 'en', 'కాణిపాకం' => 'te'],
            'summary' => 'Self-manifested Ganesha in a well.',
            'history' => 'Built in the 11th century by the Chola king Kulothunga.',
            'significance' => 'Oaths are sworn before the deity.',
            'website' => 'https://kanipakam.example.org',
            'rules' => ['dress_code' => 'Traditional dress.', 'photography' => null],
            'timings' => [
                ['kind' => 'general', 'label' => null, 'opens' => '04:00', 'closes' => '21:30', 'days' => null],
                ['kind' => 'aarti', 'label' => 'Suprabhatam', 'opens' => '04:00', 'closes' => '04:30', 'days' => null],
                ['kind' => 'darshan', 'label' => 'Weekend', 'opens' => '05:00', 'closes' => '25:00', 'days' => [0, 6]],
                ['kind' => 'aarti', 'label' => 'Ekanta seva', 'opens' => '21:00', 'closes' => null, 'days' => null],
                ['kind' => 'darshan', 'label' => 'Backwards', 'opens' => '20:00', 'closes' => '06:00', 'days' => null],
            ],
            'pujas' => [['name' => 'Abhishekam', 'description' => 'Bathing of the deity.', 'starts_at' => '05:30', 'duration_minutes' => 45, 'schedule_note' => 'Daily', 'is_free' => false]],
            'nearest' => ['railway' => 'Chittoor, 12 km', 'airport' => 'Tirupati, 80 km', 'bus' => null],
            'sources' => [['name' => 'Official website', 'url' => 'https://kanipakam.example.org']],
        ], $overrides);
    }

    private function write(array $rows): void
    {
        file_put_contents($this->file, json_encode($rows));
    }

    public function test_it_adds_a_new_temple_with_its_details_location_timings_and_sevas(): void
    {
        $this->write([$this->kanipakam()]);

        $this->artisan('temples:import-state', ['state' => 'AP', '--file' => $this->file])->assertSuccessful();

        $temple = Temple::where('slug', 'sri-varasiddhi-vinayaka-swamy-temple-kanipakam')->firstOrFail();
        $this->assertSame('AP', $temple->state->code);
        $this->assertSame('Chittoor', $temple->district->name);
        $this->assertSame('517131', $temple->pincode);
        $this->assertSame('ganesha', $temple->deity->slug);
        $this->assertTrue($temple->is_featured);
        $this->assertSame(TempleStatus::Published, $temple->status);
        // Researched from public sources, not checked with the temple.
        $this->assertSame(VerificationStatus::Community, $temple->verification_status);
        $this->assertNull($temple->last_verified_at);
        $this->assertSame('https://kanipakam.example.org', $temple->source_url);
        $this->assertStringContainsString('Nearest railway station: Chittoor, 12 km', $temple->how_to_reach);
        $this->assertSame(['Kanipakam Temple', 'కాణిపాకం'], $temple->aliases->pluck('name')->all());
        $this->assertSame(['river-ghat-temple'], $temple->categories->pluck('slug')->all());
        // A row with an impossible or backwards time is dropped, not stored
        // half-right; a ritual published with its start only is kept.
        $this->assertSame(['04:00', '04:00', '21:00'], $temple->timings->map(fn ($t) => substr((string) $t->opens_at, 0, 5))->all());
        $this->assertSame('From 9:00 PM', $temple->timings->last()->window());
        $this->assertNotNull($temple->timings->first()->notes);
        $this->assertNull($temple->pujas->first()->fee_amount);
    }

    public function test_a_temple_already_listed_under_another_name_is_not_added_again(): void
    {
        $this->seed(TelanganaTempleSeeder::class);
        $before = Temple::count();
        $chilkur = Temple::where('slug', 'chilkur-balaji-temple')->firstOrFail();
        $chilkur->update(['short_description' => 'Written by an editor.', 'address' => null]);

        $this->write([[
            'name' => 'Sri Chilkur Balaji Venkateswara Swamy Temple', 'deity' => 'venkateswara', 'famous' => true,
            'district' => 'Rangareddy', 'city' => 'Chilkur', 'address' => 'Chilkur village, Moinabad', 'pincode' => '500075',
            'lat' => (float) $chilkur->latitude + 0.0005, 'lng' => (float) $chilkur->longitude,
            'aliases' => ['Visa Balaji Temple' => 'en'], 'summary' => 'Researched summary.',
        ]]);

        $this->artisan('temples:import-state', ['state' => 'TG', '--file' => $this->file])
            ->expectsOutputToContain('listed as Chilkur Balaji Temple')
            ->assertSuccessful();

        $this->assertSame($before, Temple::count());
        $chilkur->refresh();
        // Empty fields are filled; an editor's wording is kept.
        $this->assertSame('Chilkur village, Moinabad', $chilkur->address);
        $this->assertSame('Written by an editor.', $chilkur->short_description);
        $this->assertTrue($chilkur->aliases()->where('name', 'Visa Balaji Temple')->exists());
    }

    public function test_temples_are_recognised_by_other_names_too(): void
    {
        $this->write([$this->kanipakam()]);
        $this->artisan('temples:import-state', ['state' => 'AP', '--file' => $this->file]);

        // Another source, another spelling, no pin: still the same temple.
        $this->write([$this->kanipakam(['name' => 'Kanipakam Temple', 'lat' => null, 'lng' => null, 'aliases' => []])]);
        $this->artisan('temples:import-state', ['state' => 'AP', '--file' => $this->file])->assertSuccessful();

        $this->assertSame(1, Temple::count());
    }

    public function test_a_temple_verified_by_an_editor_is_left_alone(): void
    {
        $this->write([$this->kanipakam(['address' => null])]);
        $this->artisan('temples:import-state', ['state' => 'AP', '--file' => $this->file]);
        $temple = Temple::firstOrFail();
        $temple->update(['verification_status' => VerificationStatus::Verified, 'history' => null]);

        $this->write([$this->kanipakam()]);
        $this->artisan('temples:import-state', ['state' => 'AP', '--file' => $this->file])->assertSuccessful();

        $this->assertNull($temple->fresh()->history);
        $this->assertNull($temple->fresh()->address);
    }

    public function test_re_running_changes_nothing_and_a_dry_run_writes_nothing(): void
    {
        $this->write([$this->kanipakam()]);

        $this->artisan('temples:import-state', ['state' => 'AP', '--file' => $this->file, '--dry-run' => true])
            ->expectsOutputToContain('1 new')
            ->assertSuccessful();
        $this->assertSame(0, Temple::count());

        $this->artisan('temples:import-state', ['state' => 'AP', '--file' => $this->file]);
        $this->artisan('temples:import-state', ['state' => 'AP', '--file' => $this->file])
            ->expectsOutputToContain('0 added')
            ->assertSuccessful();

        $temple = Temple::firstOrFail();
        $this->assertSame(1, Temple::count());
        $this->assertCount(3, $temple->timings);
        $this->assertCount(1, $temple->pujas);
        $this->assertCount(2, $temple->aliases);
    }

    public function test_a_same_named_temple_in_another_state_gets_its_own_page(): void
    {
        $this->write([$this->kanipakam(['name' => 'Sri Venkateswara Swamy Temple'])]);
        $this->artisan('temples:import-state', ['state' => 'AP', '--file' => $this->file]);
        $this->write([$this->kanipakam(['name' => 'Sri Venkateswara Swamy Temple', 'district' => 'Hyderabad', 'lat' => 17.4, 'lng' => 78.5, 'aliases' => []])]);
        $this->artisan('temples:import-state', ['state' => 'TG', '--file' => $this->file]);

        $this->assertSame(['sri-venkateswara-swamy-temple', 'sri-venkateswara-swamy-temple-tg'], Temple::orderBy('id')->pluck('slug')->all());
    }

    public function test_the_shipped_andhra_pradesh_and_telangana_files_import_cleanly(): void
    {
        $this->seed(TelanganaTempleSeeder::class);
        $telangana = Temple::count();

        $this->seed(StateTempleDataSeeder::class);
        $this->seed(StateTempleDataSeeder::class);

        $ap = Temple::whereHas('state', fn ($q) => $q->where('code', 'AP'))->get();
        $this->assertGreaterThanOrEqual(40, $ap->count());
        $this->assertTrue($ap->every(fn (Temple $t) => $t->district_id !== null && filled($t->short_description)));
        // A pin is left out rather than guessed, so not every temple has one.
        $this->assertGreaterThanOrEqual(40, $ap->filter->hasCoordinates()->count());
        $this->assertSame($ap->count(), $ap->unique('slug')->count());
        // The Telangana set was already listed: the file fills it in and adds only new temples.
        $this->assertSame(1, Temple::where('name', 'like', 'Chilkur Balaji%')->count());
        $this->assertGreaterThan($telangana, Temple::whereHas('state', fn ($q) => $q->where('code', 'TG'))->count());
        $this->assertSame(0, TemplePuja::whereNotNull('fee_amount')->count());
    }

    public function test_a_pin_code_of_another_state_or_a_pin_far_from_its_district_is_dropped(): void
    {
        $this->write([
            $this->kanipakam(),
            // Same district, a pin 400 km away and a Telangana PIN: both mistakes.
            $this->kanipakam(['name' => 'Sri Kanaka Durga Temple, Vijayawada', 'aliases' => [], 'pincode' => '500001', 'lat' => 16.5178, 'lng' => 80.6050]),
        ]);

        $this->artisan('temples:import-state', ['state' => 'AP', '--file' => $this->file])->assertSuccessful();

        $durga = Temple::where('slug', 'sri-kanaka-durga-temple-vijayawada')->firstOrFail();
        $this->assertNull($durga->pincode);
        $this->assertFalse($durga->hasCoordinates());
        $this->assertSame('517131', Temple::where('slug', 'sri-varasiddhi-vinayaka-swamy-temple-kanipakam')->value('pincode'));
    }

    public function test_with_refresh_a_fact_checked_record_replaces_an_earlier_import_on_untouched_temples(): void
    {
        $this->write([$this->kanipakam(), $this->kanipakam(['name' => 'Sri Kanaka Durga Temple, Vijayawada', 'aliases' => [], 'lat' => null, 'lng' => null])]);
        $this->artisan('temples:import-state', ['state' => 'AP', '--file' => $this->file]);
        // An editor has since worked on Kanaka Durga.
        Temple::where('slug', 'sri-kanaka-durga-temple-vijayawada')->update(['updated_by' => User::factory()->create()->id]);

        $checked = ['verification' => ['checked_on' => '2026-10-14', 'changes' => []]];
        $this->write([
            $this->kanipakam($checked + ['pincode' => '517132', 'history' => null, 'timings' => [['kind' => 'general', 'label' => null, 'opens' => '05:00', 'closes' => '21:00', 'days' => null]]]),
            $this->kanipakam($checked + ['name' => 'Sri Kanaka Durga Temple, Vijayawada', 'aliases' => [], 'lat' => null, 'lng' => null, 'pincode' => '520001']),
        ]);

        // Without --refresh only empty fields are filled.
        $this->artisan('temples:import-state', ['state' => 'AP', '--file' => $this->file]);
        $kanipakam = Temple::where('slug', 'sri-varasiddhi-vinayaka-swamy-temple-kanipakam')->firstOrFail();
        $this->assertSame('517131', $kanipakam->pincode);

        $this->artisan('temples:import-state', ['state' => 'AP', '--file' => $this->file, '--refresh' => true])->assertSuccessful();

        $kanipakam->refresh();
        $this->assertSame('517132', $kanipakam->pincode);
        // What the fact-check could not confirm is removed, not kept.
        $this->assertNull($kanipakam->history);
        $this->assertSame(['05:00'], $kanipakam->timings->map(fn ($t) => substr((string) $t->opens_at, 0, 5))->all());
        // The temple an editor worked on keeps their record.
        $this->assertSame('517131', Temple::where('slug', 'sri-kanaka-durga-temple-vijayawada')->value('pincode'));
    }

    public function test_the_agent_explains_a_missing_api_key_instead_of_crashing(): void
    {
        config(['services.temple_agent' => 'claude', 'services.anthropic.key' => null]);

        $this->artisan('temples:agent', ['state' => 'AP'])
            ->expectsOutputToContain('Add ANTHROPIC_API_KEY=')
            ->assertFailed();
    }

    public function test_the_agent_researches_only_temples_not_yet_listed_and_saves_its_research(): void
    {
        $this->write([$this->kanipakam()]);
        $this->artisan('temples:import-state', ['state' => 'AP', '--file' => $this->file]);
        file_put_contents($this->file, '[]');

        $researcher = new class($this->kanipakam(...)) implements TempleResearcher
        {
            public array $researched = [];

            public array $listed = [];

            public function __construct(private \Closure $row) {}

            public function discover(State $state, array $listed, int $limit): array
            {
                $this->listed = $listed;

                return [
                    ['name' => 'Kanipakam Vinayaka Temple', 'city' => 'Kanipakam', 'district' => 'Chittoor', 'why' => null],
                    ['name' => 'Sri Kalahasteeswara Swamy Temple, Srikalahasti', 'city' => 'Srikalahasti', 'district' => 'Tirupati', 'why' => null],
                ];
            }

            public function research(State $state, array $candidate): ?array
            {
                $this->researched[] = $candidate['name'];

                return ($this->row)([
                    'name' => $candidate['name'], 'deity' => 'shiva', 'district' => 'Tirupati', 'city' => 'Srikalahasti',
                    'lat' => 13.7497, 'lng' => 79.6982, 'aliases' => ['Srikalahasti Temple' => 'en'], 'categories' => ['pancha-bhoota-sthalam'],
                ]);
            }
        };
        $this->app->instance(TempleResearcher::class, $researcher);

        $this->artisan('temples:agent', ['state' => 'AP', '--file' => $this->file])
            ->expectsOutputToContain('listed     Kanipakam Vinayaka Temple')
            ->assertSuccessful();

        $this->assertContains('Sri Varasiddhi Vinayaka Swamy Temple, Kanipakam (also: Kanipakam Temple, కాణిపాకం)', $researcher->listed);
        $this->assertSame(['Sri Kalahasteeswara Swamy Temple, Srikalahasti'], $researcher->researched);
        $this->assertSame(2, Temple::count());
        $this->assertSame('Sri Kalahasteeswara Swamy Temple, Srikalahasti', json_decode(file_get_contents($this->file), true)[0]['name']);
    }
}
