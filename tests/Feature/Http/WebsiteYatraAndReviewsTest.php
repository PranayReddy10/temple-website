<?php

namespace Tests\Feature\Http;

use App\Enums\ReviewStatus;
use App\Enums\TempleStatus;
use App\Models\Devotee;
use App\Models\Setting;
use App\Models\Temple;
use App\Models\TempleReview;
use App\Models\Yatra;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The yatra planner and temple reviews on the website: the same records as the app's. */
class WebsiteYatraAndReviewsTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'https://darshansaathi.com';

    protected function setUp(): void
    {
        parent::setUp();
        config(['brand.website' => self::SITE]);
    }

    private function temple(string $name, string $slug, float $lat, float $lng): Temple
    {
        return Temple::create(['name' => $name, 'slug' => $slug, 'city' => $name, 'latitude' => $lat, 'longitude' => $lng, 'status' => TempleStatus::Published, 'published_at' => now()]);
    }

    private function signIn(): Devotee
    {
        $devotee = Devotee::factory()->create(['name' => 'Anu Reddy']);
        $this->actingAs($devotee, 'devotee_web');

        return $devotee;
    }

    public function test_a_yatra_is_planned_by_day_and_routed(): void
    {
        $a = $this->temple('Yadadri', 'yadadri', 17.588, 78.946);
        $b = $this->temple('Warangal', 'warangal', 17.995, 79.582);
        $c = $this->temple('Kolanupaka', 'kolanupaka', 17.698, 79.031);
        $devotee = $this->signIn();

        $this->get(self::SITE.'/account/yatras')->assertOk()->assertSee('Start your first yatra');
        $this->post(self::SITE.'/account/yatras', ['title' => 'Telangana weekend', 'starts_on' => '2026-11-07', 'ends_on' => '2026-11-08']);
        $yatra = Yatra::where('devotee_id', $devotee->id)->sole();
        $base = self::SITE.'/account/yatras/'.$yatra->id;

        // From the planner's search, and from a temple's page.
        $this->get($base.'?q=Warangal')->assertOk()->assertSee('Warangal');
        $this->post($base.'/stops', ['temple' => 'yadadri', 'day_number' => 1])->assertRedirect($base);
        $this->post($base.'/stops', ['temple' => 'warangal', 'day_number' => 1]);
        $this->get(self::SITE.'/temples/kolanupaka')->assertSee('Add to yatra')->assertSee('Telangana weekend');
        $this->post(self::SITE.'/temples/kolanupaka/yatra', ['yatra' => $yatra->id])->assertRedirect(self::SITE.'/temples/kolanupaka');
        $this->assertSame(['yadadri', 'warangal', 'kolanupaka'], $yatra->stops()->with('temple')->get()->pluck('temple.slug')->all());

        // Shortest order: Kolanupaka lies between Yadadri and Warangal.
        $this->post($base.'/optimise');
        $this->assertSame(['yadadri', 'kolanupaka', 'warangal'], $yatra->stops()->with('temple')->get()->pluck('temple.slug')->all());

        // Moved to day 2, then shown with the route in Maps.
        $stop = $yatra->stops()->where('temple_id', $b->id)->sole();
        $this->post($base.'/stops/'.$stop->id.'/move', ['day_number' => 2]);
        $this->assertSame(2, $stop->fresh()->day_number);
        $this->get($base)->assertOk()->assertSee('Day 2')->assertSee('https://www.google.com/maps/dir/?api=1', false)->assertSee('wa.me', false);

        $this->post($base.'/stops/'.$stop->id.'/remove');
        $this->assertSame(2, $yatra->stops()->count());

        // Another devotee's yatra is not reachable.
        $this->actingAs(Devotee::factory()->create(), 'devotee_web');
        $this->get($base)->assertNotFound();
        $this->post($base.'/delete')->assertNotFound();
    }

    public function test_reviews_are_written_moderated_and_shown(): void
    {
        $temple = $this->temple('Chilkur', 'chilkur', 17.35, 78.30);
        Setting::set('reviews_require_approval', '1');

        $this->get(self::SITE.'/temples/chilkur')->assertOk()->assertSee('No reviews yet')->assertSee('Sign in to write a review');

        $devotee = $this->signIn();
        $this->post(self::SITE.'/temples/chilkur/reviews', ['queue_rating' => 4, 'cleanliness_rating' => 5, 'wait_minutes' => 20, 'body' => 'Go early on Tuesdays.'])
            ->assertRedirect(self::SITE.'/temples/chilkur#reviews');
        $review = TempleReview::where('devotee_id', $devotee->id)->sole();
        $this->assertSame(ReviewStatus::Pending, $review->status);
        $this->get(self::SITE.'/temples/chilkur')->assertSee('Being checked')->assertDontSee('Go early on Tuesdays.</p>', false);

        // Nothing said at all: refused.
        $this->post(self::SITE.'/temples/chilkur/reviews', [])->assertSessionHasErrors('body');

        $review->forceFill(['status' => ReviewStatus::Approved])->save();
        $this->get(self::SITE.'/temples/chilkur')->assertSee('Go early on Tuesdays.')->assertSee('Usual wait')->assertSee('Anu');

        $this->post(self::SITE.'/temples/chilkur/reviews/delete');
        $this->assertSame(0, TempleReview::count());
    }

    public function test_the_temple_page_has_one_app_button(): void
    {
        $this->temple('Chilkur', 'chilkur', 17.35, 78.30);
        Setting::set('app_android_store_url', 'https://play.google.com/store/apps/details?id=com.darshansaathi.templevisit');

        $html = $this->get(self::SITE.'/temples/chilkur')->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'Open in the Android app'));
        $this->assertStringNotContainsString('Get the Android app', $html);
    }
}
