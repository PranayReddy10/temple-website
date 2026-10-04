<?php

namespace Tests\Feature\Temple;

use App\Enums\UserRole;
use App\Filament\Resources\Temples\Pages\EditTemple;
use App\Models\Temple;
use App\Models\User;
use App\Support\OfficialSite\OfficialSiteImport;
use App\Support\TempleImport\TempleLinkImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A Google Maps link alone brings the temple's OpenStreetMap record and its
 * Wikipedia article to the review screen, not only the pin.
 */
class LinkImportOpenDataTest extends TestCase
{
    use RefreshDatabase;

    private const LINK = 'https://www.google.com/maps/place/Keesaragutta+Sri+Rama+Lingeshwara+Swamy+Temple/@17.5289418,78.6891572,17z/data=!3m1!4b1!4m6!3m5!1s0x3bcb7758f52cebb9:0x4d6b80dfa937c7b8!8m2!3d17.5289418!4d78.6891572!16s%2Fm%2F09gjv8z?entry=tts';

    private const OPENING = 'Keesaragutta Temple is a Hindu temple dedicated to Shiva, located at Keesara in Medchal–Malkajgiri district of Telangana, India. It is one of the famous Shiva temples of the region.';

    private function fake(bool $osmHasWikipedia = true): void
    {
        Http::fake(function (Request $request) use ($osmHasWikipedia) {
            $url = $request->url();
            if (str_contains($url, 'overpass')) {
                return Http::response(['elements' => [
                    ['type' => 'node', 'id' => 11, 'lat' => 17.5290, 'lon' => 78.6890, 'tags' => ['amenity' => 'place_of_worship', 'religion' => 'muslim', 'name' => 'Masjid']],
                    ['type' => 'way', 'id' => 4242, 'center' => ['lat' => 17.5289, 'lon' => 78.6892], 'tags' => array_filter([
                        'amenity' => 'place_of_worship', 'religion' => 'hindu', 'name' => 'Keesaragutta Temple',
                        'phone' => '+91 40 2721 1234', 'wikidata' => 'Q6384010',
                        'wikipedia' => $osmHasWikipedia ? 'en:Keesaragutta Temple' : null,
                    ])],
                ]]);
            }
            if (str_contains($url, 'nominatim')) {
                return Http::response(['address' => ['town' => 'Keesara', 'state' => 'Telangana', 'postcode' => '501301', 'country_code' => 'in']]);
            }
            if (str_contains($url, 'wikidata.org')) {
                return Http::response(['entities' => ['Q6384010' => ['sitelinks' => ['enwiki' => ['title' => 'Keesaragutta Temple']]]]]);
            }
            if (str_contains($url, 'prop=extracts')) {
                return Http::response(['query' => ['pages' => [['extract' => "Lead.\n\n== History ==\nThe temple is said to have been consecrated by Rama himself, and the present shrine dates from the Kakatiya period and was renovated in later centuries.\n\n== Significance ==\nThe hill draws lakhs of devotees at Maha Shivaratri, when the lingas on the hill are worshipped through the night."]]]]);
            }
            if (str_contains($url, '/page/summary/Keesaragutta_Temple')) {
                return Http::response(['type' => 'standard', 'title' => 'Keesaragutta Temple', 'extract' => self::OPENING, 'wikibase_item' => 'Q6384010',
                    'content_urls' => ['desktop' => ['page' => 'https://en.wikipedia.org/wiki/Keesaragutta_Temple']]]);
            }

            return Http::response('', 404);
        });
    }

    public function test_a_maps_link_finds_the_osm_record_and_the_wikipedia_article(): void
    {
        $this->fake();
        $temple = Temple::create(['name' => 'Keesaragutta']);

        $found = OfficialSiteImport::read($temple, null, null, self::LINK);

        $this->assertSame('way/4242', $found['osm']['ref'], 'the temple, not the mosque beside it');
        $this->assertSame(['+91 40 2721 1234'], $found['phones'], 'the phone from OpenStreetMap');
        $this->assertSame('https://en.wikipedia.org/wiki/Keesaragutta_Temple', $found['wikipedia']['url']);
        $this->assertSame(self::OPENING, $found['wikipedia']['opening']);
    }

    public function test_the_article_is_found_through_wikidata_when_osm_names_only_the_item(): void
    {
        $this->fake(osmHasWikipedia: false);

        $found = OfficialSiteImport::read(Temple::create(['name' => 'Keesaragutta']), null, null, self::LINK);

        $this->assertSame('https://en.wikipedia.org/wiki/Keesaragutta_Temple', $found['wikipedia']['url']);
    }

    public function test_staff_take_the_links_and_the_wikipedia_description(): void
    {
        $this->fake();
        $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin, 'is_active' => true]));
        $temple = TempleLinkImport::create(self::LINK)['temple'];

        Livewire::test(EditTemple::class, ['record' => $temple->getRouteKey()])
            ->mountAction('reviewOfficialSite')
            ->assertSet('mountedActions.0.data.use_osm', true)
            ->assertSet('mountedActions.0.data.use_wikipedia_description', true)
            ->assertSet('mountedActions.0.data.use_wikipedia_history', true)
            ->assertSet('mountedActions.0.data.use_wikipedia_significance', true)
            ->callMountedAction(['timings' => [], 'sevas' => []])
            ->assertHasNoFormErrors();

        $temple->refresh();
        $this->assertSame('way/4242', $temple->osm_ref);
        $this->assertSame('Q6384010', $temple->wikidata_id);
        $this->assertSame('https://en.wikipedia.org/wiki/Keesaragutta_Temple', $temple->wikipedia_url);
        $this->assertSame(self::OPENING, $temple->short_description);
        $this->assertSame('wikipedia', $temple->description_source);
        $this->assertStringStartsWith('The temple is said to have been consecrated by Rama', $temple->history);
        $this->assertStringStartsWith('The hill draws lakhs of devotees', $temple->significance);
        $this->assertEqualsCanonicalizing(['history', 'significance'], $temple->wikipedia_fields);
        $this->assertSame('+91 40 2721 1234', $temple->contact_phone);
        $this->assertSame('OpenStreetMap contributors', $temple->source_name);
        $this->assertSame('https://www.openstreetmap.org/way/4242', $temple->source_url);
    }
}
