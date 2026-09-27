<?php

namespace Tests\Feature\Http;

use App\Enums\TempleStatus;
use App\Models\Temple;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The server's own address shows the product, not Laravel's welcome page. */
class HomePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_home_page_is_the_brand_with_the_ways_in(): void
    {
        Temple::create(['name' => 'Home Temple', 'slug' => 'home-temple', 'status' => TempleStatus::Published, 'published_at' => now()]);

        $this->get('/')->assertOk()
            ->assertSee(config('brand.name'))
            ->assertSee('1 temple listed')
            ->assertSee(config('brand.website'), false)
            ->assertSee('Temple portal')
            ->assertSee(url('/admin'), false)
            ->assertDontSee('Laracasts');
    }
}
