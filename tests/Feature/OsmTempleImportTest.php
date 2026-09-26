<?php

namespace Tests\Feature;

use App\Enums\TempleStatus;
use App\Enums\VerificationStatus;
use App\Models\District;
use App\Models\State;
use App\Models\Temple;
use App\Services\Osm\OsmTempleImporter;
use App\Services\Osm\TelanganaDistricts;
use App\Services\Osm\TempleCommonsPhotoFinder;
use Database\Seeders\DeitySeeder;
use Database\Seeders\StateSeeder;
use Database\Seeders\TelanganaTempleSeeder;
use Database\Seeders\TempleCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The OpenStreetMap import: counts per district, never a temple twice, never
 * an editor's wording overwritten, and photos only under a reusable licence.
 */
class OsmTempleImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([StateSeeder::class, DeitySeeder::class, TempleCategorySeeder::class]);
    }

    /** @param list<array<string, mixed>> $temples */
    protected function fakeOverpass(array $temples, array $districts = [['id' => 100, 'name' => 'Karimnagar District']]): void
    {
        Http::fake(function (Request $request) use ($temples, $districts) {
            $query = $request->data()['data'] ?? '';

            if (str_contains($query, 'admin_level"="5"')) {
                return Http::response(['elements' => array_map(
                    fn ($d) => ['type' => 'relation', 'id' => $d['id'], 'tags' => ['name' => $d['name']]],
                    $districts,
                )]);
            }

            if (str_contains($query, '["place"')) {
                return Http::response(['elements' => [
                    ['type' => 'node', 'id' => 9, 'lat' => 18.4380, 'lon' => 79.1300, 'tags' => ['name' => 'Karimnagar']],
                    ['type' => 'node', 'id' => 10, 'lat' => 18.6000, 'lon' => 79.3000, 'tags' => ['name' => 'Elgandal']],
                ]]);
            }

            return Http::response(['elements' => $temples]);
        });
    }

    protected function osmTemples(): array
    {
        return [
            ['type' => 'way', 'id' => 1, 'center' => ['lat' => 18.4390, 'lon' => 79.1310], 'tags' => [
                'amenity' => 'place_of_worship', 'religion' => 'hindu',
                'name' => 'Sri Venkateswara Swamy Temple', 'name:te' => 'శ్రీ వేంకటేశ్వర స్వామి దేవాలయం',
                'wikidata' => 'Q123', 'addr:postcode' => '505001', 'website' => 'https://example.org',
            ]],
            // The same temple, mapped again as a point beside its outline.
            ['type' => 'node', 'id' => 2, 'lat' => 18.4391, 'lon' => 79.1311, 'tags' => ['name' => 'Venkateswara Temple']],
            ['type' => 'node', 'id' => 3, 'lat' => 18.6010, 'lon' => 79.3010, 'tags' => ['name' => 'Hanuman Temple']],
            ['type' => 'node', 'id' => 4, 'lat' => 18.5000, 'lon' => 79.2000, 'tags' => ['amenity' => 'place_of_worship']],
            ['type' => 'node', 'id' => 5, 'lat' => 18.5100, 'lon' => 79.2100, 'tags' => ['name' => 'Temple']],
        ];
    }

    public function test_a_plain_scan_reports_counts_and_writes_nothing(): void
    {
        $this->fakeOverpass($this->osmTemples());
        $csv = storage_path('framework/testing/osm.csv');

        $this->artisan('temples:osm-scan', ['--pause' => 0, '--csv' => $csv])
            ->expectsOutputToContain('Report only')
            ->assertSuccessful();

        $this->assertSame(0, Temple::count());
        $lines = array_map('str_getcsv', file($csv, FILE_IGNORE_NEW_LINES));
        $outcomes = array_count_values(array_column(array_slice($lines, 1), 1));
        $this->assertEquals(['new' => 2, 'duplicate' => 1, 'unnamed' => 2], $outcomes);
    }

    public function test_import_creates_community_records_credited_to_openstreetmap(): void
    {
        $this->fakeOverpass($this->osmTemples());

        $this->artisan('temples:osm-scan', ['--pause' => 0, '--import' => true, '--csv' => storage_path('framework/testing/osm.csv')])
            ->assertSuccessful();

        $this->assertSame(2, Temple::count());

        $temple = Temple::where('osm_ref', 'way/1')->firstOrFail();
        $this->assertSame('Sri Venkateswara Swamy Temple', $temple->name);
        $this->assertSame('Karimnagar', $temple->district->name);
        $this->assertSame('Karimnagar', $temple->city);
        $this->assertSame('venkateswara', $temple->deity->slug);
        $this->assertSame('505001', $temple->pincode);
        $this->assertSame('Q123', $temple->wikidata_id);
        $this->assertSame(TempleStatus::Published, $temple->status);
        $this->assertSame(VerificationStatus::Community, $temple->verification_status);
        $this->assertSame(OsmTempleImporter::SOURCE_NAME, $temple->source_name);
        $this->assertSame('https://www.openstreetmap.org/way/1', $temple->source_url);
        $this->assertTrue($temple->aliases()->where('locale', 'te')->exists());

        $hanuman = Temple::where('osm_ref', 'node/3')->firstOrFail();
        $this->assertSame('Elgandal', $hanuman->city);
        $this->assertSame('hanuman', $hanuman->deity->slug);
    }

    public function test_running_the_import_again_creates_nothing_new(): void
    {
        $this->fakeOverpass($this->osmTemples());
        $options = ['--pause' => 0, '--import' => true, '--csv' => storage_path('framework/testing/osm.csv')];

        $this->artisan('temples:osm-scan', $options)->assertSuccessful();
        $this->artisan('temples:osm-scan', $options)->assertSuccessful();

        $this->assertSame(2, Temple::count());
    }

    public function test_drafts_on_request(): void
    {
        $this->fakeOverpass($this->osmTemples());

        $this->artisan('temples:osm-scan', ['--pause' => 0, '--import' => true, '--draft' => true, '--csv' => storage_path('framework/testing/osm.csv')])
            ->assertSuccessful();

        $this->assertTrue(Temple::all()->every(fn (Temple $t) => $t->status === TempleStatus::Draft));
    }

    public function test_a_temple_we_already_have_is_linked_not_duplicated(): void
    {
        $this->seed(TelanganaTempleSeeder::class);
        $before = Temple::count();
        $yadadri = Temple::where('slug', 'yadadri-lakshmi-narasimha-temple')->firstOrFail();

        // Mapped under a different spelling, some way from our approximate pin.
        $this->fakeOverpass([
            ['type' => 'way', 'id' => 77, 'center' => ['lat' => (float) $yadadri->latitude + 0.01, 'lon' => (float) $yadadri->longitude], 'tags' => [
                'name' => 'Sri Lakshmi Narasimha Swamy Temple, Yadadri', 'wikidata' => 'Q999',
            ]],
        ], [['id' => 5, 'name' => 'Yadadri Bhuvanagiri']]);

        $this->artisan('temples:osm-scan', ['--pause' => 0, '--import' => true, '--csv' => storage_path('framework/testing/osm.csv')])
            ->assertSuccessful();

        $this->assertSame($before, Temple::count());
        $yadadri->refresh();
        $this->assertSame('way/77', $yadadri->osm_ref);
        $this->assertSame('Q999', $yadadri->wikidata_id);
        // Its recorded source (Wikipedia) is kept.
        $this->assertNotSame(OsmTempleImporter::SOURCE_NAME, $yadadri->source_name);
    }

    public function test_a_verified_temple_keeps_its_wording(): void
    {
        $state = State::where('code', 'TG')->first();
        $district = District::create(['state_id' => $state->id, 'name' => 'Karimnagar', 'slug' => 'karimnagar']);
        $temple = Temple::forceCreate([
            'name' => 'Sri Venkateswara Temple', 'slug' => 'sri-venkateswara-temple-karimnagar',
            'state_id' => $state->id, 'district_id' => $district->id,
            'latitude' => 18.4390, 'longitude' => 79.1310,
            'status' => TempleStatus::Draft, 'verification_status' => VerificationStatus::Verified,
        ]);

        $this->fakeOverpass($this->osmTemples());
        $this->artisan('temples:osm-scan', ['--pause' => 0, '--import' => true, '--csv' => storage_path('framework/testing/osm.csv')])
            ->assertSuccessful();

        $temple->refresh();
        $this->assertSame('way/1', $temple->osm_ref);
        $this->assertNull($temple->pincode);
        $this->assertNull($temple->official_website);
    }

    public function test_osm_district_spellings_map_to_ours(): void
    {
        $this->assertSame('Rangareddy', TelanganaDistricts::canonical('Ranga Reddy'));
        $this->assertSame('Medchal–Malkajgiri', TelanganaDistricts::canonical('Medchal-Malkajgiri District'));
        $this->assertSame('Kumuram Bheem Asifabad', TelanganaDistricts::canonical('Komaram Bheem Asifabad'));
        $this->assertSame('Hanumakonda', TelanganaDistricts::canonical('Warangal Urban'));
        $this->assertSame('Warangal', TelanganaDistricts::canonical('Warangal'));
        $this->assertNull(TelanganaDistricts::canonical('Guntur'));
        $this->assertCount(33, TelanganaDistricts::NAMES);
    }

    public function test_deity_is_read_from_the_name(): void
    {
        $importer = app(OsmTempleImporter::class);

        $this->assertSame('narasimha', $importer->deitySlug('Sri Lakshmi Narasimha Swamy Temple'));
        $this->assertSame('devi', $importer->deitySlug('Sri Rajarajeshwari Devi Temple'));
        $this->assertSame('shiva', $importer->deitySlug('Sri Ramalingeswara Swamy Temple'));
        $this->assertSame('rama', $importer->deitySlug('Sri Sita Rama Chandra Swamy Temple'));
        $this->assertSame('devi', $importer->deitySlug('Pochamma Gudi'));
        $this->assertNull($importer->deitySlug('Sai Baba Mandir'));
    }

    public function test_commons_leads_are_read_from_osm_tags(): void
    {
        $importer = app(OsmTempleImporter::class);

        $this->assertSame('File:Ramappa temple.jpg', $importer->commonsFile(['wikimedia_commons' => 'File:Ramappa temple.jpg']));
        $this->assertSame('File:Thousand Pillar Temple.jpg', $importer->commonsFile(['image' => 'https://commons.wikimedia.org/wiki/File:Thousand_Pillar_Temple.jpg']));
        $this->assertSame('File:Bhadrakali.jpg', $importer->commonsFile(['image' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/a/ab/Bhadrakali.jpg/800px-Bhadrakali.jpg']));
        $this->assertNull($importer->commonsFile(['image' => 'https://lh3.googleusercontent.com/some-photo']));
    }

    public function test_only_reusable_licences_are_accepted(): void
    {
        $finder = app(TempleCommonsPhotoFinder::class);

        foreach (['CC BY-SA 4.0', 'CC BY 2.0', 'CC0', 'Public domain', 'PD-India', 'cc-by-sa-3.0'] as $ok) {
            $this->assertTrue($finder->isReusable($ok), $ok);
        }

        foreach (['CC BY-NC 2.0', 'CC BY-ND 4.0', 'GFDL', 'All rights reserved', ''] as $no) {
            $this->assertFalse($finder->isReusable($no), $no);
        }
    }

    protected function fakeWikimedia(string $licence): void
    {
        Http::fake([
            'www.wikidata.org/*' => Http::response(['claims' => ['P18' => [['mainsnak' => ['datavalue' => ['value' => 'Temple front.jpg']]]]]]),
            'commons.wikimedia.org/*' => Http::response(['query' => ['pages' => ['1' => [
                'title' => 'File:Temple front.jpg',
                'imageinfo' => [[
                    'mime' => 'image/jpeg', 'url' => 'https://upload.wikimedia.org/a/Temple_front.jpg',
                    'thumburl' => 'https://upload.wikimedia.org/thumb/a/Temple_front.jpg/1600px-Temple_front.jpg',
                    'descriptionurl' => 'https://commons.wikimedia.org/wiki/File:Temple_front.jpg',
                    'extmetadata' => ['LicenseShortName' => ['value' => $licence], 'Artist' => ['value' => '<a href="u">Ravi K</a>']],
                ]],
            ]]]]),
            'upload.wikimedia.org/*' => Http::response('JPEGBYTES', 200, ['Content-Type' => 'image/jpeg']),
        ]);
    }

    protected function templeWithWikidata(): Temple
    {
        $state = State::where('code', 'TG')->first();

        return Temple::forceCreate([
            'name' => 'Test Temple', 'slug' => 'test-temple', 'state_id' => $state->id,
            'status' => TempleStatus::Draft, 'verification_status' => VerificationStatus::Community,
            'wikidata_id' => 'Q1',
        ]);
    }

    public function test_a_commons_photo_is_stored_with_its_credit_and_licence(): void
    {
        Storage::fake(config('filesystems.media'));
        $this->fakeWikimedia('CC BY-SA 4.0');
        $temple = $this->templeWithWikidata();

        $this->artisan('temples:fetch-commons-photos')->assertSuccessful();

        $photo = $temple->photos()->firstOrFail();
        $this->assertSame('Ravi K, via Wikimedia Commons', $photo->credit);
        $this->assertSame('CC BY-SA 4.0', $photo->license);
        $this->assertSame('https://commons.wikimedia.org/wiki/File:Temple_front.jpg', $photo->source_url);
        $this->assertTrue($photo->is_primary);
        Storage::disk(config('filesystems.media'))->assertExists($photo->path);

        // A second run finds nothing left to do.
        $this->artisan('temples:fetch-commons-photos')->assertSuccessful();
        $this->assertSame(1, $temple->photos()->count());
    }

    public function test_a_photo_under_an_unusable_licence_is_skipped(): void
    {
        Storage::fake(config('filesystems.media'));
        $this->fakeWikimedia('CC BY-NC 2.0');
        $temple = $this->templeWithWikidata();

        $this->artisan('temples:fetch-commons-photos')->assertSuccessful();

        $this->assertSame(0, $temple->photos()->count());
    }
}
