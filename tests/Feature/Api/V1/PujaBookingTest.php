<?php

namespace Tests\Feature\Api\V1;

use App\Enums\BookingStatus;
use App\Enums\TempleStatus;
use App\Enums\UserRole;
use App\Filament\Temple\Resources\Bookings\BookingResource;
use App\Models\Devotee;
use App\Models\Payment;
use App\Models\PujaBooking;
use App\Models\Setting;
use App\Models\Temple;
use App\Models\TemplePuja;
use App\Models\TempleUser;
use App\Models\User;
use App\Support\Bookings\PujaBookings;
use App\Support\DevotionalClock;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Booking a seva in the app: opt-in per seva, confirmed by the money rather
 * than by the app, and verified at the counter exactly once.
 */
class PujaBookingTest extends TestCase
{
    use RefreshDatabase;

    protected Temple $temple;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temple = Temple::create(['name' => 'Booking Temple', 'slug' => 'booking-temple', 'status' => TempleStatus::Published, 'published_at' => now()]);

        Setting::set('payments_enabled', '1', 'boolean');
        Setting::set('payments_razorpay_enabled', '1', 'boolean');
        Setting::set('payments_razorpay_key_id', 'rzp_test_key');
        Setting::set('payments_razorpay_key_secret', 'rzp_secret', 'secret');
    }

    protected function puja(array $attributes = []): TemplePuja
    {
        return TemplePuja::create(array_merge([
            'temple_id' => $this->temple->id,
            'name' => 'Archana',
            'fee_amount' => 100,
            'app_booking_enabled' => true,
        ], $attributes));
    }

    protected function devotee(): Devotee
    {
        $devotee = Devotee::factory()->create(['name' => 'Anu', 'phone' => '9876543210']);
        Sanctum::actingAs($devotee, guard: 'devotee');

        return $devotee;
    }

    protected function templeAdmin(Temple $temple): User
    {
        $user = User::factory()->create(['role' => UserRole::TempleAdmin, 'is_active' => true]);
        TempleUser::create(['temple_id' => $temple->id, 'user_id' => $user->id, 'requested_at' => now(), 'approved_at' => now()]);

        return $user;
    }

    protected function today(): string
    {
        return DevotionalClock::now()->toDateString();
    }

    // --- The listing ---

    public function test_the_temple_page_says_which_sevas_can_be_booked_in_the_app(): void
    {
        $this->puja(['name' => 'Archana', 'kind' => 'puja']);
        $this->puja(['name' => 'Laddu', 'kind' => 'prasadam', 'app_booking_enabled' => false]);

        $this->getJson('/api/v1/temples/booking-temple')->assertOk()
            ->assertJsonPath('data.pujas.0.app_booking.enabled', true)
            ->assertJsonPath('data.pujas.0.app_booking.requires_payment', true)
            ->assertJsonPath('data.pujas.0.app_booking.amount_paise', 10000)
            ->assertJsonPath('data.pujas.0.kind', 'puja')
            ->assertJsonPath('data.pujas.1.app_booking.enabled', false)
            ->assertJsonPath('data.pujas.1.kind', 'prasadam');
    }

    public function test_app_booking_cannot_be_switched_on_without_a_price_or_free(): void
    {
        $puja = $this->puja(['fee_amount' => null, 'is_free' => false]);

        // There is nothing to charge and nothing to confirm.
        $this->assertFalse($puja->fresh()->app_booking_enabled);
        $this->assertFalse($puja->fresh()->isBookableInApp());

        $free = $this->puja(['fee_amount' => null, 'is_free' => true]);
        $this->assertTrue($free->fresh()->isBookableInApp());
    }

    // --- Placing a booking ---

    public function test_a_free_seva_is_confirmed_at_once_with_a_code_to_show(): void
    {
        $this->devotee();
        $puja = $this->puja(['is_free' => true, 'booking_instructions' => 'Report at the seva counter.']);

        $response = $this->postJson("/api/v1/temples/booking-temple/pujas/{$puja->id}/bookings", [
            'booked_for' => $this->today(), 'people' => 2, 'gotram' => 'Bharadwaja',
        ])->assertCreated()
            ->assertJsonPath('data.status.value', 'confirmed')
            ->assertJsonPath('data.is_free', true)
            ->assertJsonPath('data.people', 2)
            ->assertJsonPath('data.gotram', 'Bharadwaja')
            ->assertJsonPath('data.devotee_name', 'Anu')
            ->assertJsonPath('data.puja.instructions', 'Report at the seva counter.')
            ->assertJsonPath('checkout', null);

        $this->assertMatchesRegularExpression('/^SV[2-9A-HJ-NP-Z]{8}$/', $response->json('data.reference'));
        $this->assertStringContainsString('/bookings/'.$response->json('data.code'), $response->json('data.qr_url'));
        $this->assertStringNotContainsString('/bookings/1', $response->json('data.qr_url'));
    }

    public function test_a_priced_seva_waits_for_its_payment_and_is_confirmed_by_the_gateway(): void
    {
        $devotee = $this->devotee();
        $puja = $this->puja(['fee_amount' => 100, 'fee_per_person' => true]);
        Http::fake(['api.razorpay.com/v1/orders' => Http::response(['id' => 'order_BK1'])]);

        $response = $this->postJson("/api/v1/temples/booking-temple/pujas/{$puja->id}/bookings", [
            'booked_for' => $this->today(), 'people' => 3, 'platform' => 'android', 'mode' => 'sdk',
        ])->assertCreated()
            ->assertJsonPath('data.status.value', 'pending_payment')
            ->assertJsonPath('data.amount_paise', 30000)
            ->assertJsonPath('checkout.sdk.gateway', 'razorpay')
            ->assertJsonPath('checkout.sdk.amount_paise', 30000)
            ->assertJsonPath('checkout.payment.purpose', 'puja_booking');

        $this->assertStringContainsString('Archana', $response->json('checkout.sdk.description'));
        $reference = $response->json('data.reference');
        $uuid = $response->json('checkout.payment.id');

        // Still pending: the app's word alone confirms nothing.
        $this->getJson("/api/v1/me/bookings/{$reference}")->assertOk()->assertJsonPath('data.status.value', 'pending_payment');

        $this->postJson("/api/v1/me/payments/{$uuid}/confirm", [
            'razorpay_payment_id' => 'pay_BK1', 'razorpay_order_id' => 'order_BK1',
            'razorpay_signature' => hash_hmac('sha256', 'order_BK1|pay_BK1', 'rzp_secret'),
        ])->assertOk()->assertJsonPath('data.status', 'paid');

        $this->getJson("/api/v1/me/bookings/{$reference}")->assertOk()
            ->assertJsonPath('data.status.value', 'confirmed')
            ->assertJsonPath('data.payment.status', 'paid');

        // No plan was started by a booking's payment.
        $this->assertSame(0, $devotee->subscriptions()->count());
        $this->getJson('/api/v1/me/bookings')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_failed_payment_cancels_the_booking_and_frees_the_slot(): void
    {
        $this->devotee();
        $puja = $this->puja(['booking_capacity_per_day' => 1]);
        Http::fake(['api.razorpay.com/v1/orders' => Http::response(['id' => 'order_F'])]);

        $reference = $this->postJson("/api/v1/temples/booking-temple/pujas/{$puja->id}/bookings", ['booked_for' => $this->today()])
            ->assertCreated()->json('data.reference');

        // Full while the first payment is open.
        $this->postJson("/api/v1/temples/booking-temple/pujas/{$puja->id}/bookings", ['booked_for' => $this->today()])
            ->assertUnprocessable()->assertJsonValidationErrors('booked_for');

        $payment = PujaBooking::query()->where('reference', $reference)->firstOrFail()->payment;
        app(\App\Support\Payments\Payments::class)->apply($payment, Payment::FAILED, reason: 'Declined.');

        $this->getJson("/api/v1/me/bookings/{$reference}")->assertJsonPath('data.status.value', 'cancelled');
        $this->postJson("/api/v1/temples/booking-temple/pujas/{$puja->id}/bookings", ['booked_for' => $this->today()])->assertCreated();
    }

    public function test_a_seva_the_temple_has_not_opened_cannot_be_booked(): void
    {
        // Temples first: publishing one while a devotee is signed in is
        // refused, as it should be.
        $other = Temple::create(['name' => 'Other', 'slug' => 'other', 'status' => TempleStatus::Published]);
        $elsewhere = TemplePuja::create(['temple_id' => $other->id, 'name' => 'Elsewhere', 'is_free' => true, 'app_booking_enabled' => true]);
        $this->devotee();
        $off = $this->puja(['app_booking_enabled' => false]);

        $this->postJson("/api/v1/temples/booking-temple/pujas/{$off->id}/bookings", ['booked_for' => $this->today()])
            ->assertUnprocessable()->assertJsonValidationErrors('puja');

        // A puja from another temple under this temple's URL is not found.
        $this->postJson("/api/v1/temples/booking-temple/pujas/{$elsewhere->id}/bookings", ['booked_for' => $this->today()])
            ->assertNotFound();
    }

    public function test_the_booking_window_and_party_size_are_the_temples(): void
    {
        $this->devotee();
        $puja = $this->puja(['is_free' => true, 'booking_advance_days' => 7, 'max_people_per_booking' => 4]);

        $this->postJson("/api/v1/temples/booking-temple/pujas/{$puja->id}/bookings", ['booked_for' => DevotionalClock::now()->subDay()->toDateString()])
            ->assertUnprocessable()->assertJsonValidationErrors('booked_for');
        $this->postJson("/api/v1/temples/booking-temple/pujas/{$puja->id}/bookings", ['booked_for' => DevotionalClock::now()->addDays(8)->toDateString()])
            ->assertUnprocessable()->assertJsonValidationErrors('booked_for');
        $this->postJson("/api/v1/temples/booking-temple/pujas/{$puja->id}/bookings", ['booked_for' => $this->today(), 'people' => 5])
            ->assertUnprocessable()->assertJsonValidationErrors('people');
        $this->postJson("/api/v1/temples/booking-temple/pujas/{$puja->id}/bookings", ['booked_for' => DevotionalClock::now()->addDays(7)->toDateString(), 'people' => 4])
            ->assertCreated();
    }

    public function test_booking_needs_an_account_and_shows_only_your_own(): void
    {
        $puja = $this->puja(['is_free' => true]);
        $this->postJson("/api/v1/temples/booking-temple/pujas/{$puja->id}/bookings", ['booked_for' => $this->today()])->assertUnauthorized();

        $this->devotee();
        $mine = $this->postJson("/api/v1/temples/booking-temple/pujas/{$puja->id}/bookings", ['booked_for' => $this->today()])->json('data.reference');

        $other = Devotee::factory()->create();
        Sanctum::actingAs($other, guard: 'devotee');
        $this->getJson("/api/v1/me/bookings/{$mine}")->assertNotFound();
        $this->getJson('/api/v1/me/bookings')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_devotee_can_cancel_before_the_day_but_not_after_being_received(): void
    {
        $this->devotee();
        $puja = $this->puja(['is_free' => true]);
        $reference = $this->postJson("/api/v1/temples/booking-temple/pujas/{$puja->id}/bookings", ['booked_for' => $this->today()])->json('data.reference');

        $booking = PujaBooking::query()->where('reference', $reference)->firstOrFail();
        app(PujaBookings::class)->verify($booking, $this->templeAdmin($this->temple));

        $this->postJson("/api/v1/me/bookings/{$reference}/cancel")->assertUnprocessable();

        $again = $this->postJson("/api/v1/temples/booking-temple/pujas/{$puja->id}/bookings", ['booked_for' => $this->today()])->json('data.reference');
        $this->postJson("/api/v1/me/bookings/{$again}/cancel")->assertOk()->assertJsonPath('data.status.value', 'cancelled');
    }

    // --- The counter ---

    public function test_the_temple_verifies_a_code_once_and_the_second_scan_is_refused(): void
    {
        $this->devotee();
        $puja = $this->puja(['is_free' => true]);
        $reference = $this->postJson("/api/v1/temples/booking-temple/pujas/{$puja->id}/bookings", ['booked_for' => $this->today()])->json('data.reference');
        $booking = PujaBooking::query()->where('reference', $reference)->firstOrFail();
        $staff = $this->templeAdmin($this->temple);

        $first = app(PujaBookings::class)->verify($booking, $staff);
        $this->assertSame(PujaBookings::VERIFIED, $first['outcome']);
        $this->assertSame(BookingStatus::Verified, $booking->fresh()->status);
        $this->assertSame($staff->id, $booking->fresh()->verified_by);

        // The same screenshot shown again.
        $second = app(PujaBookings::class)->verify($booking->fresh(), $staff);
        $this->assertSame(PujaBookings::ALREADY_VERIFIED, $second['outcome']);

        $this->getJson("/api/v1/me/bookings/{$reference}")->assertJsonPath('data.status.value', 'verified')->assertJsonPath('data.can_cancel', false);
    }

    public function test_an_unpaid_booking_cannot_be_verified(): void
    {
        $this->devotee();
        $puja = $this->puja();
        Http::fake(['api.razorpay.com/v1/orders' => Http::response(['id' => 'order_U'])]);
        $reference = $this->postJson("/api/v1/temples/booking-temple/pujas/{$puja->id}/bookings", ['booked_for' => $this->today()])->json('data.reference');

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(PujaBookings::class)->verify(PujaBooking::query()->where('reference', $reference)->firstOrFail(), $this->templeAdmin($this->temple));
    }

    public function test_another_temples_team_cannot_verify_or_even_see_the_booking(): void
    {
        $elsewhere = Temple::create(['name' => 'Elsewhere', 'status' => TempleStatus::Published]);
        $this->devotee();
        $puja = $this->puja(['is_free' => true]);
        $this->postJson("/api/v1/temples/booking-temple/pujas/{$puja->id}/bookings", ['booked_for' => $this->today()])->assertCreated();
        $booking = PujaBooking::query()->firstOrFail();

        $stranger = $this->templeAdmin($elsewhere);

        // 'web' by name: Sanctum's acting-as made the devotee guard the default.
        $this->actingAs($stranger, 'web');
        $this->assertSame([], BookingResource::getEloquentQuery()->pluck('id')->all());

        $this->expectException(AuthorizationException::class);
        app(PujaBookings::class)->verify($booking, $stranger);
    }

    public function test_the_portal_lists_only_the_teams_own_bookings_and_the_scanner_finds_them(): void
    {
        $this->devotee();
        $puja = $this->puja(['is_free' => true]);
        $code = $this->postJson("/api/v1/temples/booking-temple/pujas/{$puja->id}/bookings", ['booked_for' => $this->today()])->json('data.code');
        $staff = $this->templeAdmin($this->temple);

        $this->actingAs($staff, 'web');
        $this->assertCount(1, BookingResource::getEloquentQuery()->get());
        $this->get('/temple/bookings')->assertOk();
        $this->get('/temple/scan-booking')->assertOk();

        // The public page a phone camera opens says what it is, and nothing about the account.
        $this->get("/bookings/{$code}")->assertOk()->assertSee('Archana')->assertDontSee('@example');
        $this->get('/bookings/'.str_repeat('x', 28))->assertOk()->assertSee('Booking not found');
    }

    public function test_staff_see_every_booking_in_the_admin(): void
    {
        $this->devotee();
        $puja = $this->puja(['is_free' => true]);
        $this->postJson("/api/v1/temples/booking-temple/pujas/{$puja->id}/bookings", ['booked_for' => $this->today()])->assertCreated();

        $admin = User::factory()->create(['role' => UserRole::SuperAdmin, 'is_active' => true]);
        $this->actingAs($admin, 'web')->get('/admin/seva-bookings')->assertOk()->assertSee('Archana');
        $this->actingAs($admin, 'web')->get('/admin/scan-booking')->assertOk();
    }

    public function test_a_refund_voids_the_booking(): void
    {
        $this->devotee();
        $puja = $this->puja();
        Http::fake(['api.razorpay.com/v1/orders' => Http::response(['id' => 'order_R'])]);
        $reference = $this->postJson("/api/v1/temples/booking-temple/pujas/{$puja->id}/bookings", ['booked_for' => $this->today()])->json('data.reference');
        $booking = PujaBooking::query()->where('reference', $reference)->firstOrFail();

        $payments = app(\App\Support\Payments\Payments::class);
        $payments->apply($booking->payment, Payment::PAID, 'pay_R');
        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);

        $payments->refunded($booking->payment->fresh());
        $this->assertSame(BookingStatus::Refunded, $booking->fresh()->status);
    }

    public function test_the_counters_scanner_verifies_once_and_refuses_the_same_code_again(): void
    {
        $this->devotee();
        $puja = $this->puja(['is_free' => true, 'booking_instructions' => 'Report at the seva counter.']);
        $response = $this->postJson("/api/v1/temples/booking-temple/pujas/{$puja->id}/bookings", ['booked_for' => $this->today(), 'people' => 3])->assertCreated();
        $reference = $response->json('data.reference');
        $qr = $response->json('data.qr_url');
        $staff = $this->templeAdmin($this->temple);

        $this->actingAs($staff, 'web');

        // The QR as the devotee's phone shows it, then the button.
        Livewire::test(\App\Filament\Temple\Pages\ScanBooking::class)
            ->call('scan', $qr)
            ->assertSee($reference)
            ->assertSee('Anu')
            ->assertSee('Report at the seva counter.')
            ->assertSee('Mark received and verified')
            ->call('verify')
            ->assertSee('Verified')
            ->assertSee('The code is now used');

        $this->assertSame(BookingStatus::Verified, PujaBooking::query()->where('reference', $reference)->firstOrFail()->status);

        // The same screenshot, shown again: refused at once, naming who took it.
        Livewire::test(\App\Filament\Temple\Pages\ScanBooking::class)
            ->call('scan', $qr)
            ->assertSee('Already verified')
            ->assertSee($staff->name)
            ->assertDontSee('Mark received and verified');

        // The reference typed by hand finds the booking too; junk does not.
        Livewire::test(\App\Filament\Temple\Pages\ScanBooking::class)
            ->call('scan', strtolower($reference))
            ->assertSee('Already verified');
        Livewire::test(\App\Filament\Temple\Pages\ScanBooking::class)
            ->call('scan', 'https://example.com/passport/ABCDEFGHIJKLMNOPQRST')
            ->assertSet('scannedCode', null)
            ->assertSee('not a seva booking code');
    }

    public function test_another_temples_scanner_does_not_know_the_code(): void
    {
        $elsewhere = Temple::create(['name' => 'Elsewhere', 'status' => TempleStatus::Published]);
        $this->devotee();
        $puja = $this->puja(['is_free' => true]);
        $code = $this->postJson("/api/v1/temples/booking-temple/pujas/{$puja->id}/bookings", ['booked_for' => $this->today()])->json('data.code');

        $this->actingAs($this->templeAdmin($elsewhere), 'web');

        Livewire::test(\App\Filament\Temple\Pages\ScanBooking::class)
            ->call('scan', $code)
            ->assertSet('scannedCode', null)
            ->assertSee('No booking of yours matches this code');

        // Staff, in the admin, see every temple's.
        $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin, 'is_active' => true]), 'web');
        Livewire::test(\App\Filament\Pages\ScanBooking::class)
            ->call('scan', $code)
            ->assertSee('Archana')
            ->assertSee('Mark received and verified');
    }
}
