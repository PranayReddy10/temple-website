<?php

namespace Tests\Feature\Temple;

use App\Enums\TempleStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Temples\Pages\EditTemple;
use App\Filament\Resources\Temples\Pages\ListTemples;
use App\Models\District;
use App\Models\State;
use App\Models\Temple;
use App\Models\User;
use App\Support\OfficialSite\OfficialSiteImport;
use App\Support\TempleImport\MapsLink;
use App\Support\TempleImport\TempleLinkImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Temples from a Google Maps link and their website: the pin from the
 * link, the address the map has there, details from the website, and
 * photos that may be copied, all for staff to review.
 */
class TempleLinkImportTest extends TestCase
{
    use RefreshDatabase;

    private const SHORT = 'https://maps.app.goo.gl/Swarnagiri1';

    private const FULL = 'https://www.google.com/maps/place/Swarnagiri+Sri+Venkateswara+Swamy+Temple/@17.5084,78.8701,17z/data=!3m1!4b1!4m6!3m5!1s0x3bcb7f9e5b9c3b1d:0x8a7c2f5e2d1b4c3a!8m2!3d17.5098!4d78.8862!16s%2Fg%2F11h0x1y2z3';

    private const SITE = 'https://www.swarnagiritemple.com';

    private State $state;

    private District $district;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('filesystems.media'));
        $this->state = State::create(['name' => 'Telangana', 'slug' => 'telangana', 'code' => 'TG']);
        $this->district = District::create(['state_id' => $this->state->id, 'name' => 'Yadadri Bhuvanagiri', 'slug' => 'yadadri-bhuvanagiri']);
    }

    private function jpeg(): string
    {
        $img = imagecreatetruecolor(900, 600);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 120, 40));
        ob_start();
        imagejpeg($img);

        return (string) ob_get_clean();
    }

    private function fakeWeb(): void
    {
        $html = ['Content-Type' => 'text/html; charset=utf-8'];
        $fixture = fn (string $n) => file_get_contents(base_path('tests/Fixtures/official-site/'.$n));
        Http::fake([
            self::SHORT => Http::response('', 302, ['Location' => self::FULL]),
            'nominatim.openstreetmap.org/reverse*' => Http::response(['address' => [
                'amenity' => 'Swarnagiri Temple', 'road' => 'Manepally Hills', 'town' => 'Bhongir',
                'state_district' => 'Yadadri Bhuvanagiri District', 'state' => 'Telangana', 'postcode' => '508116', 'country_code' => 'in',
            ]]),
            'commons.wikimedia.org/w/api.php*' => Http::response(['query' => ['pages' => [
                ['title' => 'File:Swarnagiri temple gopuram.jpg', 'coordinates' => [['dist' => 40.2]], 'imageinfo' => [[
                    'url' => 'https://upload.wikimedia.org/a/gopuram.jpg', 'thumburl' => 'https://upload.wikimedia.org/thumb/gopuram-1600.jpg',
                    'width' => 4000, 'height' => 3000, 'thumbwidth' => 1600, 'thumbheight' => 1200, 'mime' => 'image/jpeg',
                    'descriptionurl' => 'https://commons.wikimedia.org/wiki/File:Swarnagiri_temple_gopuram.jpg',
                    'extmetadata' => ['Artist' => ['value' => '<a href="//commons.wikimedia.org/wiki/User:Ravi">Ravi K</a>'], 'LicenseShortName' => ['value' => 'CC BY-SA 4.0'], 'LicenseUrl' => ['value' => 'https://creativecommons.org/licenses/by-sa/4.0']],
                ]]],
                ['title' => 'File:Somewhere else.jpg', 'coordinates' => [['dist' => 500]], 'imageinfo' => [[
                    'url' => 'https://upload.wikimedia.org/a/x.jpg', 'width' => 3000, 'height' => 2000, 'mime' => 'image/jpeg', 'descriptionurl' => 'https://commons.wikimedia.org/wiki/File:X.jpg',
                    'extmetadata' => ['Artist' => ['value' => 'Someone'], 'LicenseShortName' => ['value' => 'CC BY-NC 2.0']],
                ]]],
                ['title' => 'File:Tiny.jpg', 'imageinfo' => [['url' => 'https://upload.wikimedia.org/a/t.jpg', 'width' => 300, 'height' => 200, 'mime' => 'image/jpeg', 'extmetadata' => ['LicenseShortName' => ['value' => 'CC0']]]]],
            ]]]),
            'upload.wikimedia.org/*' => Http::response($this->jpeg(), 200, ['Content-Type' => 'image/jpeg']),
            self::SITE.'/images/*' => Http::response($this->jpeg(), 200, ['Content-Type' => 'image/jpeg']),
            self::SITE.'/darshan-timings' => Http::response($fixture('timings.html'), 200, $html),
            self::SITE.'/sevas.html' => Http::response($fixture('sevas.html'), 200, $html),
            self::SITE.'/sitemap.xml' => Http::response('', 404),
            self::SITE.'*' => Http::response($fixture('home.html'), 200, $html),
            '*' => Http::response('', 404),
        ]);
    }

    private function staff(): User
    {
        return User::factory()->create(['role' => UserRole::SuperAdmin, 'is_active' => true]);
    }

    public function test_a_maps_link_gives_the_name_and_the_places_own_pin(): void
    {
        $place = MapsLink::parse(self::FULL);
        $this->assertSame('Swarnagiri Sri Venkateswara Swamy Temple', $place['name']);
        // The place's pin, not where the map happened to be centred.
        $this->assertSame([17.5098, 78.8862], [$place['latitude'], $place['longitude']]);

        $this->assertSame([17.4239, 78.4105], array_values(array_intersect_key(MapsLink::parse('https://maps.google.com/?q=17.4239,78.4105'), array_flip(['latitude', 'longitude']))));
        $this->assertSame('Hare Krishna Golden Temple', MapsLink::parse('https://www.google.com/maps/search/?api=1&query=Hare+Krishna+Golden+Temple')['name']);
        $this->assertFalse(MapsLink::isMapsLink('https://example.com/maps'));
    }

    public function test_a_short_link_is_followed_without_reading_googles_page(): void
    {
        $this->fakeWeb();

        $place = MapsLink::read(self::SHORT);

        $this->assertSame(17.5098, $place['latitude']);
        Http::assertNotSent(fn ($request) => str_starts_with($request->url(), 'https://www.google.com/'));
    }

    public function test_reading_links_finds_the_place_details_and_photos_for_review(): void
    {
        $this->fakeWeb();
        $temple = Temple::create(['name' => 'Swarnagiri']);

        $found = OfficialSiteImport::read($temple, self::SITE, null, self::SHORT);

        $this->assertSame([17.5098, 78.8862], [$found['latitude'], $found['longitude']]);
        $this->assertSame('508116', $found['place']['pincode']);
        $this->assertSame($this->district->id, $found['place']['district_id']);
        $this->assertSame('Bhongir', $found['place']['city']);
        // Only the free, large photo; the non-commercial and the tiny one are left out.
        $this->assertCount(1, $found['commons_photos']);
        $this->assertSame('Ravi K', $found['commons_photos'][0]['credit']);
        $this->assertSame('https://www.swarnagiritemple.com/images/temple-front.jpg', $found['images'][0]['url']);
        $this->assertSame(self::SHORT, $temple->refresh()->google_maps_url);
        $this->assertNull($temple->latitude, 'nothing on the listing changes before review');
    }

    public function test_staff_take_the_place_and_copy_free_photos_with_their_credit(): void
    {
        $this->fakeWeb();
        $this->actingAs($this->staff());
        $temple = Temple::create(['name' => 'Swarnagiri']);
        OfficialSiteImport::read($temple, self::SITE, null, self::SHORT);

        Livewire::test(EditTemple::class, ['record' => $temple->getRouteKey()])
            ->mountAction('reviewOfficialSite')
            ->assertSet('mountedActions.0.data.use_region', true)
            ->assertSet('mountedActions.0.data.commons_photos', [0])
            ->callMountedAction(['website_photos' => [], 'timings' => [], 'sevas' => []])
            ->assertHasNoFormErrors();

        $temple->refresh();
        $this->assertSame([17.5098, 78.8862], [(float) $temple->latitude, (float) $temple->longitude]);
        $this->assertSame($this->state->id, $temple->state_id);
        $this->assertSame($this->district->id, $temple->district_id);
        $this->assertSame('Bhongir', $temple->city);

        $photo = $temple->photos()->sole();
        $this->assertSame('Ravi K', $photo->credit);
        $this->assertSame('CC BY-SA 4.0 · Wikimedia Commons', $photo->license);
        $this->assertSame('https://commons.wikimedia.org/wiki/File:Swarnagiri_temple_gopuram.jpg', $photo->source_url);
        $this->assertTrue($photo->is_primary, 'the first photo of a temple becomes its cover');
        Storage::disk(config('filesystems.media'))->assertExists($photo->path);
    }

    public function test_website_photos_need_the_temples_permission(): void
    {
        $this->fakeWeb();
        $this->actingAs($this->staff());
        $temple = Temple::create(['name' => 'Swarnagiri']);
        OfficialSiteImport::read($temple, self::SITE, null, self::SHORT);

        $review = Livewire::test(EditTemple::class, ['record' => $temple->getRouteKey()])->mountAction('reviewOfficialSite');
        $review->set('mountedActions.0.data.commons_photos', [])
            ->set('mountedActions.0.data.website_photos', ['0'])
            ->callMountedAction()
            ->assertHasFormErrors(['website_permission']);
        $this->assertSame(0, $temple->photos()->count());

        $review->set('mountedActions.0.data.website_permission', true)
            ->callMountedAction()
            ->assertHasNoFormErrors();
        $photo = $temple->photos()->sole();
        $this->assertSame('Courtesy of swarnagiritemple.com', $photo->credit);
        $this->assertSame('Used with the temple\'s permission', $photo->license);
    }

    public function test_pasted_links_become_draft_temples_and_repeats_are_skipped(): void
    {
        $this->fakeWeb();
        $this->actingAs($this->staff());
        $nearby = Temple::create(['name' => 'Sri Venkateswara Swamy Temple Swarnagiri', 'latitude' => 17.5100, 'longitude' => 78.8865, 'status' => TempleStatus::Published]);
        $nearby->delete(); // not there any more: the link is added.

        Livewire::test(ListTemples::class)
            ->callAction('importLinks', data: ['links' => self::SHORT.' '.self::SITE."\n\nnot a link at all"])
            ->assertHasNoActionErrors();

        $temple = Temple::where('name', 'Swarnagiri Sri Venkateswara Swamy Temple')->sole();
        $this->assertSame(TempleStatus::Draft, $temple->status);
        $this->assertSame([17.5098, 78.8862], [(float) $temple->latitude, (float) $temple->longitude]);
        $this->assertSame(self::SITE, rtrim($temple->official_website, '/'));
        $this->assertNotNull($temple->official_import);
        $this->assertNull($temple->official_import_reviewed_at);

        // The same place again, by the same link or a few metres away, is not added twice.
        $this->assertSame('duplicate', TempleLinkImport::create(self::SHORT)['status']);
        $this->assertSame('duplicate', TempleLinkImport::create('https://maps.google.com/?q=17.5099,78.8863', null, 'Swarnagiri Temple')['status']);
        $this->assertSame(1, Temple::count());
    }

    public function test_the_command_adds_temples_from_a_file(): void
    {
        $this->fakeWeb();
        $file = tempnam(sys_get_temp_dir(), 'links');
        file_put_contents($file, "maps,website\n".self::SHORT.','.self::SITE."\n");

        $this->artisan('temples:import-links', ['file' => $file])->assertSuccessful();

        $this->assertSame(1, Temple::where('google_maps_url', self::SHORT)->count());
        unlink($file);
    }
}
