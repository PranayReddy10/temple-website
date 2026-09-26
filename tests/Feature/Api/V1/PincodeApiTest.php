<?php

namespace Tests\Feature\Api\V1;

use App\Models\State;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PincodeApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_pin_code_fills_in_state_district_and_towns(): void
    {
        $state = State::query()->where('name', 'Telangana')->first() ?? State::create(['name' => 'Telangana', 'slug' => 'telangana', 'code' => 'TG']);
        Http::fake(['api.postalpincode.in/*' => Http::response([[
            'Status' => 'Success',
            'PostOffice' => [
                ['Name' => 'Kolanupaka', 'District' => 'Yadadri Bhuvanagiri', 'State' => 'Telangana', 'Block' => 'Alair'],
                ['Name' => 'Alair', 'District' => 'Yadadri Bhuvanagiri', 'State' => 'Telangana', 'Block' => 'NA'],
            ],
        ]])]);

        $this->getJson('/api/v1/pincode/508101')
            ->assertOk()
            ->assertJsonPath('data.state', 'Telangana')
            ->assertJsonPath('data.state_id', $state->id)
            ->assertJsonPath('data.district', 'Yadadri Bhuvanagiri')
            ->assertJsonPath('data.places.0.name', 'Kolanupaka')
            ->assertJsonPath('data.places.0.block', 'Alair')
            ->assertJsonPath('data.places.1.block', null);

        // Cached: the second ask does not go out again.
        $this->getJson('/api/v1/pincode/508101')->assertOk();
        Http::assertSentCount(1);
    }

    public function test_an_unknown_pin_code_is_a_404(): void
    {
        // India Post says no, and so does the map.
        Http::fake([
            'api.postalpincode.in/*' => Http::response([['Status' => 'Error', 'PostOffice' => null]]),
            'nominatim.openstreetmap.org/*' => Http::response([]),
        ]);

        $this->getJson('/api/v1/pincode/999999')->assertNotFound();
    }

    public function test_the_lookup_being_down_is_a_503_not_a_500_or_a_wrong_code(): void
    {
        Http::fake([
            'api.postalpincode.in/*' => Http::response('', 503),
            'nominatim.openstreetmap.org/*' => Http::response('', 503),
        ]);

        $this->getJson('/api/v1/pincode/500001')->assertStatus(503);
    }

    public function test_only_six_digit_codes_are_asked_about(): void
    {
        Http::fake();

        $this->getJson('/api/v1/pincode/12345')->assertNotFound();
        $this->getJson('/api/v1/pincode/012345')->assertNotFound();
        Http::assertNothingSent();
    }
}
