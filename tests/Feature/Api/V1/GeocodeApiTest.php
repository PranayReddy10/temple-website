<?php

namespace Tests\Feature\Api\V1;

use App\Models\State;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A dropped pin fills in the address, and the PIN code lookup asks the map
 * before telling anybody their code does not exist.
 */
class GeocodeApiTest extends TestCase
{
    use RefreshDatabase;

    protected function telangana(): State
    {
        return State::query()->where('name', 'Telangana')->first() ?? State::create(['name' => 'Telangana', 'slug' => 'telangana', 'code' => 'TG']);
    }

    protected function nominatimAddress(): array
    {
        return [
            'place_id' => 1,
            'display_name' => 'Someshwara Temple, Kolanupaka',
            'address' => [
                'amenity' => 'Someshwara Temple',
                'road' => 'Temple Street',
                'village' => 'Kolanupaka',
                'state_district' => 'Yadadri Bhuvanagiri District',
                'state' => 'Telangana',
                'postcode' => '508101',
                'country' => 'India',
                'country_code' => 'in',
            ],
        ];
    }

    public function test_a_pin_on_the_map_fills_in_pin_code_village_district_and_state(): void
    {
        $state = $this->telangana();
        Http::fake(['nominatim.openstreetmap.org/reverse*' => Http::response($this->nominatimAddress())]);

        $this->getJson('/api/v1/geocode/reverse?lat=17.6&lng=79.0')
            ->assertOk()
            ->assertJsonPath('data.pincode', '508101')
            ->assertJsonPath('data.state', 'Telangana')
            ->assertJsonPath('data.state_id', $state->id)
            ->assertJsonPath('data.district', 'Yadadri Bhuvanagiri')
            ->assertJsonPath('data.city', 'Kolanupaka')
            ->assertJsonPath('data.address', 'Temple Street')
            ->assertJsonPath('data.places.0.name', 'Kolanupaka');

        // Cached by position: the same courtyard is not asked about twice.
        $this->getJson('/api/v1/geocode/reverse?lat=17.60001&lng=79.00001')->assertOk();
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request->header('User-Agent')[0] ?? '', config('brand.name')));
    }

    public function test_nothing_on_the_map_is_a_404(): void
    {
        Http::fake(['nominatim.openstreetmap.org/reverse*' => Http::response(['error' => 'Unable to geocode'])]);
        $this->getJson('/api/v1/geocode/reverse?lat=0&lng=0')->assertNotFound();

        $this->getJson('/api/v1/geocode/reverse?lat=91&lng=1')->assertUnprocessable();
    }

    public function test_the_map_being_down_is_a_503_not_a_wrong_address(): void
    {
        Http::fake(['nominatim.openstreetmap.org/reverse*' => Http::response('', 503)]);
        $this->getJson('/api/v1/geocode/reverse?lat=1&lng=1')->assertStatus(503);
    }

    public function test_a_pin_code_india_post_does_not_know_is_still_found_on_the_map(): void
    {
        $state = $this->telangana();
        Http::fake([
            'api.postalpincode.in/*' => Http::response([['Status' => 'Error', 'PostOffice' => null]]),
            'nominatim.openstreetmap.org/search*' => Http::response([$this->nominatimAddress()]),
        ]);

        $this->getJson('/api/v1/pincode/508101')
            ->assertOk()
            ->assertJsonPath('data.state_id', $state->id)
            ->assertJsonPath('data.district', 'Yadadri Bhuvanagiri')
            ->assertJsonPath('data.places.0.name', 'Kolanupaka');
    }

    public function test_the_map_is_asked_when_india_post_is_down(): void
    {
        $this->telangana();
        Http::fake([
            'api.postalpincode.in/*' => Http::response('', 503),
            'nominatim.openstreetmap.org/search*' => Http::response([$this->nominatimAddress()]),
        ]);

        $this->getJson('/api/v1/pincode/508101')->assertOk()->assertJsonPath('data.state', 'Telangana');
    }

    public function test_both_sources_down_is_said_as_unreachable_not_as_an_unknown_code(): void
    {
        Http::fake([
            'api.postalpincode.in/*' => Http::response('', 503),
            'nominatim.openstreetmap.org/*' => Http::response('', 503),
        ]);

        $this->getJson('/api/v1/pincode/500001')->assertStatus(503);
    }

    public function test_a_code_neither_source_knows_is_a_404(): void
    {
        Http::fake([
            'api.postalpincode.in/*' => Http::response([['Status' => 'Error', 'PostOffice' => null]]),
            'nominatim.openstreetmap.org/*' => Http::response([]),
        ]);

        $this->getJson('/api/v1/pincode/999999')->assertNotFound();
    }
}
