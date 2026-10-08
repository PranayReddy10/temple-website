<?php

namespace Tests\Feature\Http;

use App\Enums\BookingStatus;
use App\Enums\TempleStatus;
use App\Models\Devotee;
use App\Models\Payment;
use App\Models\PujaBooking;
use App\Models\Setting;
use App\Models\Temple;
use App\Models\TemplePuja;
use App\Support\DevotionalClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Everything a devotee does in the app, on the website: sign up and in,
 * book a seva, give to the hundi, save temples, see bookings with their
 * counter code, edit the profile and delete the account. For iPhone users
 * until there is an iOS app, and anyone without the app.
 */
class WebsiteDevoteeTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'https://darshansaathi.com';

    protected Temple $temple;

    protected function setUp(): void
    {
        parent::setUp();
        config(['brand.website' => self::SITE, 'brand.url' => 'https://temple.darshansaathi.com', 'app.url' => 'https://temple.darshansaathi.com']);

        $this->temple = Temple::create(['name' => 'Chilkur Balaji Temple', 'slug' => 'chilkur', 'city' => 'Hyderabad', 'accepts_donations' => true, 'status' => TempleStatus::Published, 'published_at' => now()]);
        $this->approvePayments($this->temple);

        Setting::set('payments_enabled', '1', 'boolean');
        Setting::set('payments_razorpay_enabled', '1', 'boolean');
        Setting::set('payments_razorpay_key_id', 'rzp_test_key');
        Setting::set('payments_razorpay_key_secret', 'rzp_secret', 'secret');
    }

    protected function seva(array $attributes = []): TemplePuja
    {
        return TemplePuja::create($attributes + ['temple_id' => $this->temple->id, 'name' => 'Archana', 'fee_amount' => 100, 'app_booking_enabled' => true]);
    }

    protected function signIn(?Devotee $devotee = null): Devotee
    {
        $devotee ??= Devotee::factory()->create(['name' => 'Anu', 'phone' => '9876543210']);
        $this->actingAs($devotee, 'devotee_web');

        return $devotee;
    }

    public function test_a_devotee_registers_signs_out_and_signs_in_again(): void
    {
        $this->get(self::SITE.'/register')->assertOk()->assertSee('Create your account')->assertSee('noindex', false);

        $this->post(self::SITE.'/register', [
            'name' => 'Lakshmi', 'email' => 'lakshmi@example.com', 'password' => 'secret-pass', 'password_confirmation' => 'secret-pass',
        ])->assertRedirect(self::SITE.'/account');
        $this->assertAuthenticated('devotee_web');
        $this->get(self::SITE.'/account')->assertOk()->assertSee('Namaste, Lakshmi');

        $this->post(self::SITE.'/logout')->assertRedirect(self::SITE.'/');
        $this->assertGuest('devotee_web');

        $this->post(self::SITE.'/login', ['identifier' => 'LAKSHMI@example.com', 'password' => 'wrong'])->assertSessionHasErrors('identifier');
        $this->assertGuest('devotee_web');

        $this->post(self::SITE.'/login', ['identifier' => 'Lakshmi@example.com', 'password' => 'secret-pass'])->assertRedirect(self::SITE.'/account');
        $this->assertAuthenticated('devotee_web');
    }

    public function test_private_pages_ask_to_sign_in_and_come_back_afterwards(): void
    {
        $seva = $this->seva();
        $book = self::SITE.'/temples/chilkur/sevas/'.$seva->id.'/book';

        $this->get($book)->assertRedirect(self::SITE.'/login');
        $this->get(self::SITE.'/account')->assertRedirect(self::SITE.'/login');

        Devotee::factory()->create(['email' => 'anu@example.com', 'password' => 'secret-pass']);
        $this->get($book);
        $this->post(self::SITE.'/login', ['identifier' => 'anu@example.com', 'password' => 'secret-pass'])->assertRedirect($book);

        // ?next= on the sign-in link, from a public page; never another site.
        $this->post(self::SITE.'/logout');
        $this->get(self::SITE.'/login?next='.urlencode('https://evil.example/x'));
        $this->post(self::SITE.'/login', ['identifier' => 'anu@example.com', 'password' => 'secret-pass'])->assertRedirect(self::SITE.'/account');
    }

    public function test_the_sevas_page_is_public_and_tells_search_engines_the_prices(): void
    {
        $this->seva(['name' => 'Abhishekam', 'fee_amount' => 250]);
        $this->seva(['name' => 'Laddu prasadam', 'kind' => 'prasadam', 'app_booking_enabled' => false]);

        $this->get(self::SITE.'/temples/chilkur/sevas')->assertOk()
            ->assertDontSee('noindex', false)
            ->assertSee('<link rel="canonical" href="'.self::SITE.'/temples/chilkur/sevas">', false)
            ->assertSee('Book sevas online at Chilkur Balaji Temple, Hyderabad')
            ->assertSee('"@type":"Offer","price":"250.00","priceCurrency":"INR"', false)
            ->assertSee('Sign in to book')
            ->assertSee('Book at the temple counter');

        $this->get(self::SITE.'/sitemap-temples-1.xml')->assertOk()
            ->assertSee(self::SITE.'/temples/chilkur/sevas', false)
            ->assertSee(self::SITE.'/temples/chilkur/donate', false);

        $this->get(self::SITE.'/temples/unknown/sevas')->assertNotFound();
    }

    public function test_a_free_seva_is_confirmed_at_once_with_its_counter_code(): void
    {
        $seva = $this->seva(['name' => 'Free darshan', 'is_free' => true, 'fee_amount' => null]);
        $devotee = $this->signIn();

        $this->get(self::SITE.'/temples/chilkur/sevas/'.$seva->id.'/book')->assertOk()->assertSee('Book now');

        $response = $this->post(self::SITE.'/temples/chilkur/sevas/'.$seva->id.'/book', [
            'booked_for' => DevotionalClock::now()->addDay()->toDateString(), 'people' => 2,
        ]);

        $booking = PujaBooking::where('devotee_id', $devotee->id)->firstOrFail();
        $this->assertSame(BookingStatus::Confirmed, $booking->status);
        $response->assertRedirect(self::SITE.'/account/bookings/'.$booking->reference);

        $this->get(self::SITE.'/account/bookings/'.$booking->reference)->assertOk()
            ->assertSee('Free darshan')->assertSee('class="qr"', false)->assertSee('Show this at the temple counter')->assertSee('Download ticket')->assertSee('https://wa.me/?text=', false);
        $this->get(self::SITE.'/account/bookings')->assertOk()->assertSee('Free darshan');

        // Someone else's booking is not there for another devotee.
        $this->signIn(Devotee::factory()->create());
        $this->get(self::SITE.'/account/bookings/'.$booking->reference)->assertNotFound();
    }

    public function test_a_paid_seva_goes_to_checkout_and_returns_to_the_booking(): void
    {
        $seva = $this->seva(['fee_amount' => 100]);
        $devotee = $this->signIn();

        $response = $this->post(self::SITE.'/temples/chilkur/sevas/'.$seva->id.'/book', [
            'booked_for' => DevotionalClock::now()->addDay()->toDateString(), 'people' => 3,
        ]);

        $booking = PujaBooking::where('devotee_id', $devotee->id)->with('payment')->firstOrFail();
        $this->assertSame(BookingStatus::PendingPayment, $booking->status);
        $this->assertSame(30000, $booking->payment->amount_paise);
        $this->assertSame('web', $booking->payment->meta['client']);
        $this->assertStringStartsWith('https://temple.darshansaathi.com/pay/'.$booking->payment->uuid.'?', $response->headers->get('Location'));

        $this->get(self::SITE.'/account/payments/'.$booking->payment->uuid)->assertRedirect(self::SITE.'/account/bookings/'.$booking->reference);
        $this->get(self::SITE.'/account/bookings/'.$booking->reference)->assertOk()->assertSee('Pay now')->assertDontSee('class="qr"', false);
    }

    public function test_a_devotee_gives_to_the_hundi(): void
    {
        $devotee = $this->signIn();

        $this->get(self::SITE.'/temples/chilkur/donate')->assertOk()->assertSee('Continue to pay');
        $response = $this->post(self::SITE.'/temples/chilkur/donate', ['amount' => 501, 'purpose' => array_key_first(\App\Models\TempleDonation::PURPOSES)]);

        $donation = $devotee->donations()->with('payment')->firstOrFail();
        $this->assertSame(50100, $donation->amount_paise);
        $this->assertStringStartsWith('https://temple.darshansaathi.com/pay/', $response->headers->get('Location'));

        $donation->payment->forceFill(['status' => Payment::PAID, 'paid_at' => now()])->save();
        $this->get(self::SITE.'/account/payments/'.$donation->payment->uuid)->assertRedirect(self::SITE.'/account/donations');
        $this->get(self::SITE.'/account/donations')->assertOk()->assertSee('₹501')->assertSee('Chilkur Balaji Temple');
    }

    public function test_temples_are_saved_from_their_page(): void
    {
        $this->get(self::SITE.'/temples/chilkur')->assertOk()->assertSee(self::SITE.'/login?next=', false);

        $devotee = $this->signIn();
        $this->get(self::SITE.'/temples/chilkur')->assertSee('♡ Save');
        $this->post(self::SITE.'/temples/chilkur/save')->assertRedirect(self::SITE.'/temples/chilkur');
        $this->assertTrue($devotee->savedTemples()->whereKey($this->temple->id)->exists());
        $this->get(self::SITE.'/temples/chilkur')->assertSee('♥ Saved');
        $this->get(self::SITE.'/account/saved')->assertOk()->assertSee('Chilkur Balaji Temple');

        $this->post(self::SITE.'/temples/chilkur/save');
        $this->assertFalse($devotee->savedTemples()->whereKey($this->temple->id)->exists());
    }

    public function test_the_profile_is_edited_and_the_account_deleted(): void
    {
        $devotee = $this->signIn();

        $this->post(self::SITE.'/account/profile', ['name' => 'Anu Reddy', 'email' => 'anu@example.com', 'phone' => '9876543210'])
            ->assertRedirect(self::SITE.'/account/profile');
        $this->assertSame('Anu Reddy', $devotee->fresh()->name);

        $this->get(self::SITE.'/account/passport')->assertOk()->assertSee('My temple passport');

        $this->post(self::SITE.'/account/delete', ['confirm' => 'nope'])->assertSessionHasErrors('confirm');
        $this->post(self::SITE.'/account/delete', ['confirm' => 'DELETE'])->assertRedirect(self::SITE.'/');
        $this->assertSoftDeleted($devotee);
        $this->assertGuest('devotee_web');
    }

    public function test_google_sign_in_needs_googles_own_csrf_cookie(): void
    {
        Setting::set('auth_google_enabled', '1', 'boolean');
        Setting::set('auth_google_server_client_id', 'web-client.apps.googleusercontent.com');

        $this->get(self::SITE.'/login')->assertOk()->assertSee('data-client_id="web-client.apps.googleusercontent.com"', false);

        $this->post(self::SITE.'/login/google', ['credential' => 'x', 'g_csrf_token' => 'a'])
            ->assertRedirect(self::SITE.'/login')->assertSessionHasErrors('identifier');
        $this->assertGuest('devotee_web');
    }

    public function test_search_engines_skip_the_private_pages(): void
    {
        $this->get(self::SITE.'/robots.txt')->assertSee('Disallow: /account')->assertSee('Disallow: /temples/*/sevas/*/book');
        $this->get(self::SITE.'/login')->assertSee('noindex', false);
    }
}
