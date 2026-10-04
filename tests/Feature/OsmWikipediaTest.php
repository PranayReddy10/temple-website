<?php

namespace Tests\Feature;

use App\Enums\TempleStatus;
use App\Enums\VerificationStatus;
use App\Models\State;
use App\Models\Temple;
use App\Services\Osm\OsmTempleImporter;
use App\Services\Osm\TempleWikipediaFinder;
use Database\Seeders\StateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Wikipedia for imported temples: the right article only, its opening as a
 * description only where there is none, and always credited.
 */
class OsmWikipediaTest extends TestCase
{
    use RefreshDatabase;

    private const RAMAPPA = 'The Ramappa Temple, also known as the Rudreswara Temple, is a Kakatiya style Hindu temple dedicated to the god Shiva, located in Palampet village of Mulugu district of Telangana, India. It was built in 1213 by Recharla Rudra.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([StateSeeder::class]);
    }

    private function temple(array $attributes = []): Temple
    {
        return Temple::forceCreate($attributes + [
            'name' => 'Ramappa Temple', 'slug' => 'ramappa-temple',
            'state_id' => State::where('code', 'TG')->value('id'),
            'latitude' => 18.2591, 'longitude' => 79.9431,
            'status' => TempleStatus::Published, 'published_at' => now(),
            'verification_status' => VerificationStatus::Community,
            'source_name' => OsmTempleImporter::SOURCE_NAME, 'source_url' => 'https://www.openstreetmap.org/way/1',
        ]);
    }

    private function fakeWikipedia(array $geosearch = []): void
    {
        Http::fake(function (Request $request) use ($geosearch) {
            $url = $request->url();
            if (str_contains($url, 'wikidata.org') && str_contains($url, 'wbgetentities')) {
                return Http::response(['entities' => ['Q3635467' => ['sitelinks' => ['enwiki' => ['title' => 'Ramappa Temple']]]]]);
            }
            if (str_contains($url, 'list=geosearch')) {
                return Http::response(['query' => ['geosearch' => $geosearch]]);
            }
            if (str_contains($url, '/page/summary/Ramappa_Temple')) {
                return Http::response([
                    'type' => 'standard', 'title' => 'Ramappa Temple', 'extract' => self::RAMAPPA, 'wikibase_item' => 'Q3635467',
                    'content_urls' => ['desktop' => ['page' => 'https://en.wikipedia.org/wiki/Ramappa_Temple']],
                ]);
            }

            return Http::response([], 404);
        });
    }

    public function test_osm_names_the_article(): void
    {
        $importer = app(OsmTempleImporter::class);

        $this->assertSame('https://en.wikipedia.org/wiki/Ramappa_Temple', $importer->wikipediaUrl(['wikipedia' => 'en:Ramappa Temple']));
        $this->assertSame('https://te.wikipedia.org/wiki/రామప్ప_దేవాలయం', $importer->wikipediaUrl(['wikipedia:te' => 'రామప్ప దేవాలయం']));
        $this->assertNull($importer->wikipediaUrl(['wikipedia' => 'Ramappa Temple']));
    }

    public function test_the_article_from_wikidata_gives_an_empty_description_credited(): void
    {
        $this->fakeWikipedia();
        $temple = $this->temple(['wikidata_id' => 'Q3635467']);

        $this->artisan('temples:fetch-wikipedia')->assertSuccessful();

        $temple->refresh();
        $this->assertSame('https://en.wikipedia.org/wiki/Ramappa_Temple', $temple->wikipedia_url);
        $this->assertSame(self::RAMAPPA, $temple->short_description);
        $this->assertSame('wikipedia', $temple->description_source);

        // Shown with its credit on the website and in the app.
        $this->get('/temples/ramappa-temple')->assertOk()
            ->assertSee('Kakatiya style')
            ->assertSee('https://creativecommons.org/licenses/by-sa/4.0/', false)
            ->assertSee('© OpenStreetMap contributors');
        $this->getJson('/api/v1/temples/ramappa-temple')->assertOk()
            ->assertJsonPath('data.about.description_credit.text', 'From Wikipedia, CC BY-SA 4.0')
            ->assertJsonPath('data.about.description_credit.url', 'https://en.wikipedia.org/wiki/Ramappa_Temple');

        // Rewritten by an editor, it is ours, and the credit goes.
        $temple->update(['short_description' => 'Our own words.']);
        $this->assertNull($temple->refresh()->description_source);
    }

    public function test_a_nearby_article_counts_only_when_its_title_names_the_temple(): void
    {
        $this->fakeWikipedia([
            ['title' => 'Palampet', 'dist' => 120],
            ['title' => 'Ramappa Temple', 'dist' => 40],
        ]);
        $temple = $this->temple();

        $this->artisan('temples:fetch-wikipedia')->assertSuccessful();

        $temple->refresh();
        $this->assertSame('https://en.wikipedia.org/wiki/Ramappa_Temple', $temple->wikipedia_url);
        // The item found becomes the Commons photo lead.
        $this->assertSame('Q3635467', $temple->wikidata_id);

        $this->fakeWikipedia([['title' => 'Palampet', 'dist' => 120]]);
        $other = $this->temple(['name' => 'Kotagullu Temple', 'slug' => 'kotagullu']);
        $this->artisan('temples:fetch-wikipedia')->assertSuccessful();
        $this->assertNull($other->refresh()->wikipedia_url, 'a village article is not the temple');
    }

    public function test_written_and_checked_descriptions_are_never_replaced(): void
    {
        $this->fakeWikipedia();
        $written = $this->temple(['wikidata_id' => 'Q3635467', 'short_description' => 'Written by our editors.']);

        $this->artisan('temples:fetch-wikipedia')->assertSuccessful();

        $written->refresh();
        $this->assertSame('Written by our editors.', $written->short_description);
        $this->assertNull($written->description_source);
        $this->assertSame('https://en.wikipedia.org/wiki/Ramappa_Temple', $written->wikipedia_url, 'the link is still added');

        $this->fakeWikipedia();
        $checked = $this->temple(['name' => 'Ramappa', 'slug' => 'ramappa-2', 'wikidata_id' => 'Q3635467', 'verification_status' => VerificationStatus::Verified]);
        $this->artisan('temples:fetch-wikipedia')->assertSuccessful();
        $this->assertNull($checked->refresh()->short_description);
    }

    public function test_a_long_opening_is_cut_at_a_sentence(): void
    {
        $finder = app(TempleWikipediaFinder::class);
        $long = str_repeat('The temple stands on a hill above the village and is reached by steps. ', 20);

        $opening = $finder->opening($long);

        $this->assertLessThanOrEqual(600, mb_strlen($opening));
        $this->assertStringEndsWith('steps.', $opening);
        $this->assertNull($finder->opening('Too short.'));
    }
}
