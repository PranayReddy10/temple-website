<?php

namespace Tests\Feature\Http;

use App\Enums\TempleStatus;
use App\Models\District;
use App\Models\State;
use App\Models\Temple;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** "How to reach": directions first, the address once, and how to get there by train, air and bus. */
class TempleReachCardTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_card_leads_with_directions_and_lists_the_ways_there(): void
    {
        $state = State::create(['name' => 'Andhra Pradesh', 'slug' => 'andhra-pradesh', 'code' => 'AP', 'type' => 'state']);
        $district = District::create(['state_id' => $state->id, 'name' => 'Srikakulam', 'slug' => 'srikakulam']);
        Temple::create([
            'name' => 'Sri Suryanarayana Swamy Temple, Arasavalli', 'slug' => 'arasavalli', 'state_id' => $state->id, 'district_id' => $district->id,
            'city' => 'Arasavalli', 'address' => 'Arasavalli, near Srikakulam', 'pincode' => '532401', 'latitude' => 18.2879, 'longitude' => 83.9136,
            'contact_phone' => '89789 14660', 'official_website' => 'https://www.srisuryatemple.com',
            'how_to_reach' => "Nearest railway station: Srikakulam Road, about 13 km\nNearest airport: Visakhapatnam, about 110 km\nBy bus: Srikakulam bus station, about 2 km",
            'status' => TempleStatus::Published, 'published_at' => now(),
        ]);

        $page = $this->get('/temples/arasavalli')->assertOk();

        $page->assertSee('Get directions')
            ->assertSee('destination=18.2879000,83.9136000', false)
            ->assertSee('href="tel:8978914660"', false)
            // Each part of the address once.
            ->assertSee('Arasavalli, near Srikakulam, Srikakulam, Andhra Pradesh, 532401')
            ->assertSee('GPS 18.28790, 83.91360')
            ->assertSeeInOrder(['Getting there', 'By train', 'Srikakulam Road, about 13 km', 'By air', 'Visakhapatnam, about 110 km', 'By bus', 'Srikakulam bus station, about 2 km']);
        // Shown in the card, not again as a section of the page.
        $this->assertSame(1, substr_count($page->getContent(), 'Srikakulam Road, about 13 km'));
    }
}
