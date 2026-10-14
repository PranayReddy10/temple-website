<?php

namespace Tests\Feature;

use App\Models\State;
use App\Models\Temple;
use App\Support\TempleAgent\FreeTempleResearcher;
use App\Support\TempleAgent\TempleResearcher;
use Database\Seeders\DeitySeeder;
use Database\Seeders\StateSeeder;
use Database\Seeders\TempleCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The free research agent: Wikidata, Wikipedia, OpenStreetMap and the
 * temple's own website, with no API key.
 */
class FreeTempleAgentTest extends TestCase
{
    use RefreshDatabase;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([StateSeeder::class, DeitySeeder::class, TempleCategorySeeder::class]);
        $this->file = tempnam(sys_get_temp_dir(), 'temples').'.json';
        file_put_contents($this->file, '[]');
        config(['services.temple_agent' => 'free', 'services.anthropic.key' => null]);
    }

    protected function tearDown(): void
    {
        @unlink($this->file);

        parent::tearDown();
    }

    private function fakeTheWeb(): void
    {
        Http::fake([
            'query.wikidata.org/*' => Http::response(['results' => ['bindings' => [
                [
                    'item' => ['value' => 'http://www.wikidata.org/entity/Q100'], 'itemLabel' => ['value' => 'Kanipakam Vinayaka Temple'],
                    'title' => ['value' => 'Kanipakam Vinayaka Temple'], 'placeLabel' => ['value' => 'Kanipakam'],
                    'coord' => ['value' => 'Point(79.035 13.2794)'], 'links' => ['value' => '9'],
                ],
                [
                    'item' => ['value' => 'http://www.wikidata.org/entity/Q200'], 'itemLabel' => ['value' => 'Srikalahasti Temple'],
                    'title' => ['value' => 'Srikalahasti Temple'], 'placeLabel' => ['value' => 'Srikalahasti'],
                    'coord' => ['value' => 'Point(79.6982 13.7497)'], 'links' => ['value' => '20'],
                ],
                // Not in English: skipped.
                ['item' => ['value' => 'http://www.wikidata.org/entity/Q300'], 'itemLabel' => ['value' => 'Q300'], 'links' => ['value' => '1']],
            ]]]),
            'www.wikidata.org/wiki/Special:EntityData/Q200.json' => Http::response(['entities' => ['Q200' => [
                'labels' => ['en' => ['value' => 'Srikalahasti Temple'], 'te' => ['value' => 'శ్రీకాళహస్తి దేవాలయం']],
                'aliases' => ['en' => [['value' => 'Sri Kalahasteeswara Temple']]],
                'sitelinks' => ['enwiki' => ['title' => 'Srikalahasti Temple']],
                'claims' => [
                    'P625' => [['mainsnak' => ['datavalue' => ['value' => ['latitude' => 13.7497, 'longitude' => 79.6982]]]]],
                    'P856' => [['mainsnak' => ['datavalue' => ['value' => 'https://srikalahasthitemple.org']]]],
                    'P571' => [['mainsnak' => ['datavalue' => ['value' => ['time' => '+1100-00-00T00:00:00Z', 'precision' => 7]]]]],
                    'P825' => [['mainsnak' => ['datavalue' => ['value' => ['id' => 'Q11']]]]],
                ],
            ]]]),
            'www.wikidata.org/w/api.php*' => Http::response(['entities' => ['Q11' => ['labels' => ['en' => ['value' => 'Shiva']]]]]),
            'en.wikipedia.org/*' => Http::response(['query' => ['pages' => ['1' => ['extract' => "Srikalahasti Temple is a Hindu temple in Srikalahasti, Andhra Pradesh. It is dedicated to Shiva as Vayu linga. The temple is known for Rahu-Ketu puja.\n\n== History ==\nThe inner temple was built in the 5th century. The outer complex was built in the 11th century by the Chola king Rajendra Chola I.\n\n== Festivals ==\nMaha Shivaratri is the main festival, held in February or March."]]]]),
            'srikalahasthitemple.org*' => Http::response('<html><head><title>Sri Kalahasteeswara Swamy Devasthanam</title></head><body><h2>Temple Timings</h2><p>Darshan: 6:00 AM to 9:00 PM</p><p>Phone: 08578-222240</p></body></html>', 200, ['Content-Type' => 'text/html']),
            'nominatim.openstreetmap.org/*' => Http::response(['address' => [
                'town' => 'Srikalahasti', 'state_district' => 'Tirupati District', 'state' => 'Andhra Pradesh', 'postcode' => '517644', 'road' => 'Temple Road',
            ]]),
            '*' => Http::response('', 404),
        ]);
    }

    public function test_it_finds_and_adds_temples_from_free_sources_without_an_api_key(): void
    {
        $this->fakeTheWeb();
        // Already listed: found by Wikidata, but not researched again.
        Temple::create([
            'name' => 'Sri Varasiddhi Vinayaka Swamy Temple, Kanipakam', 'slug' => 'kanipakam', 'state_id' => State::where('code', 'AP')->value('id'),
            'latitude' => 13.2795, 'longitude' => 79.0351, 'status' => 'published',
        ]);

        $this->artisan('temples:agent', ['state' => 'AP', '--limit' => 5, '--file' => $this->file])
            ->expectsOutputToContain('listed     Kanipakam Vinayaka Temple')
            ->expectsOutputToContain('sources: Wikidata, Wikipedia, Official website, OpenStreetMap')
            ->assertSuccessful();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.anthropic.com'));

        $temple = Temple::where('name', 'Srikalahasti Temple')->with('district', 'deity', 'aliases', 'timings')->firstOrFail();
        $this->assertSame('Tirupati', $temple->district->name);
        $this->assertSame('shiva', $temple->deity->slug);
        $this->assertSame('517644', $temple->pincode);
        $this->assertSame('11th century', $temple->built_period);
        $this->assertSame('https://srikalahasthitemple.org', $temple->official_website);
        $this->assertStringStartsWith('Srikalahasti Temple is a Hindu temple', $temple->short_description);
        $this->assertStringContainsString('Rajendra Chola I', $temple->history);
        $this->assertStringContainsString('Maha Shivaratri', $temple->significance);
        $this->assertContains('శ్రీకాళహస్తి దేవాలయం', $temple->aliases->pluck('name')->all());
        $this->assertNotEmpty($temple->timings);
        $this->assertSame('06:00', substr((string) $temple->timings->first()->opens_at, 0, 5));
        $this->assertSame(1, Temple::where('name', 'like', '%Kanipakam%')->count());

        $saved = json_decode(file_get_contents($this->file), true);
        $this->assertSame(['Wikidata', 'Wikipedia', 'Official website', 'OpenStreetMap'], array_column($saved[0]['sources'], 'name'));
    }

    public function test_the_free_researcher_is_the_default_and_claude_only_runs_when_chosen(): void
    {
        // With TEMPLE_AGENT unset, the configuration chooses the free researcher.
        $this->assertSame('free', (require config_path('services.php'))['temple_agent']);
        $this->assertInstanceOf(FreeTempleResearcher::class, $this->app->make(TempleResearcher::class));

        config(['services.temple_agent' => 'claude']);
        $this->expectExceptionMessage('No Anthropic API key');
        $this->app->make(TempleResearcher::class);
    }

    public function test_wikipedia_text_is_cut_into_whole_sentences(): void
    {
        $sections = FreeTempleResearcher::sections("Lead one. Lead two.\n== History ==\nBuilt in 1213.[3] Restored in 1990.\n=== Later ===\nMore.");

        $this->assertSame('Lead one. Lead two.', $sections['']);
        $this->assertSame('Built in 1213. Restored in 1990.', FreeTempleResearcher::sentences($sections['History'], 5, 500));
        $this->assertSame('Built in 1213.', FreeTempleResearcher::sentences($sections['History'], 5, 20));
        $this->assertSame('venkateswara', FreeTempleResearcher::deity('Sri Venkateswara Swamy Temple'));
        $this->assertSame('murugan', FreeTempleResearcher::deity('Subrahmanya Swamy Temple, Mopidevi'));
        $this->assertNull(FreeTempleResearcher::deity('Ghanpur Group of Temples'));
    }
}
