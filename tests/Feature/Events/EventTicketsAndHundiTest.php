<?php

namespace Tests\Feature\Events;

use App\Enums\BookingStatus;
use App\Enums\EventStatus;
use App\Enums\TempleStatus;
use App\Enums\UserRole;
use App\Models\Devotee;
use App\Models\EventRegistration;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\Temple;
use App\Models\TempleDonation;
use App\Models\TempleEvent;
use App\Models\TempleUser;
use App\Models\User;
use App\Support\DevotionalClock;
use App\Support\Finance\Settlements;
use App\Support\Payments\Payments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Bhajan gatherings devotees say they will join, events they buy tickets
 * for, and gifts to a temple's hundi: paid through the gateway, received at
 * the gate once, and settled with the temple alongside seva bookings.
 */
class EventTicketsAndHundiTest extends TestCase
{
    use RefreshDatabase;

    protected Temple $temple;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temple = Temple::create(['name' => 'Sri Rama Temple', 'slug' => 'sri-rama', 'city' => 'Bhadrachalam', 'status' => TempleStatus::Published, 'published_at' => now()]);

        Setting::set('payments_enabled', '1', 'boolean');
        Setting::set('payments_razorpay_enabled', '1', 'boolean');
        Setting::set('payments_razorpay_key_id', 'rzp_test_key');
        Setting::set('payments_razorpay_key_secret', 'rzp_secret', 'secret');
    }

    protected function event(array $attributes = []): TempleEvent
    {
        $event = TempleEvent::create(array_merge([
            'temple_id' => $this->temple->id,
            'type' => 'bhajan',
            'title' => 'Thursday bhajans',
            'starts_on' => DevotionalClock::now()->toDateString(),
            'is_all_day' => false,
            'starts_at' => '19:00',
            'recurrence' => 'weekly',
            'group_name' => 'Sri Rama Bhajan Mandali',
            'registration_enabled' => true,
            'songs' => "Raghupati Raghava\nHare Rama Hare Krishna",
        ], $attributes));
        $event->forceFill(['status' => EventStatus::Published, 'published_at' => now()])->save();

        return $event->refresh();
    }

    protected function devotee(): Devotee
    {
        $devotee = Devotee::factory()->create(['name' => 'Anu', 'phone' => null]);
        Sanctum::actingAs($devotee, guard: 'devotee');

        return $devotee;
    }

    public function test_a_weekly_bhajan_lists_its_next_dates_and_songs(): void
    {
        $event = $this->event();
        $today = DevotionalClock::now();

        $this->getJson('/api/v1/events?type=bhajan')
            ->assertOk()
            ->assertJsonPath('data.0.recurrence', 'weekly')
            ->assertJsonPath('data.0.ends_on', null)
            ->assertJsonPath('data.0.next_on', $today->toDateString())
            ->assertJsonPath('data.0.next_dates.1', $today->copy()->addWeek()->toDateString())
            ->assertJsonPath('data.0.group_name', 'Sri Rama Bhajan Mandali')
            ->assertJsonPath('data.0.songs.1', 'Hare Rama Hare Krishna')
            ->assertJsonPath('data.0.registration.enabled', true)
            ->assertJsonPath('data.0.registration.is_paid', false);

        // Weeks on, a weekly gathering with no last date is still upcoming.
        $event->forceFill(['starts_on' => $today->copy()->subWeeks(3)->toDateString()])->save();
        $this->getJson('/api/v1/events/'.$event->id)
            ->assertOk()
            ->assertJsonPath('data.next_on', $today->toDateString());
    }

    public function test_joining_a_free_gathering_is_confirmed_at_once_and_counted(): void
    {
        $event = $this->event(['capacity' => 3]);
        $this->devotee();

        $this->postJson('/api/v1/events/'.$event->id.'/join', ['people' => 2])
            ->assertCreated()
            ->assertJsonPath('data.status.value', 'confirmed')
            ->assertJsonPath('data.kind', 'event')
            ->assertJsonPath('checkout', null);

        // One place per devotee per date.
        $this->postJson('/api/v1/events/'.$event->id.'/join', ['people' => 1])->assertUnprocessable();

        $this->getJson('/api/v1/events/'.$event->id)->assertJsonPath('data.registration.going', 2);

        // Two of three places are taken.
        $this->devotee();
        $this->postJson('/api/v1/events/'.$event->id.'/join', ['people' => 2])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('people');

        // A date the event does not take place on.
        $this->postJson('/api/v1/events/'.$event->id.'/join', ['occurs_on' => DevotionalClock::now()->addDay()->toDateString()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('occurs_on');
    }

    public function test_paid_tickets_wait_for_the_gateway_and_are_received_once_at_the_gate(): void
    {
        $event = $this->event(['type' => 'program', 'title' => 'Music evening', 'recurrence' => 'none', 'ticket_price_paise' => 20000]);
        $devotee = $this->devotee();

        $response = $this->postJson('/api/v1/events/'.$event->id.'/join', ['people' => 2, 'platform' => 'android'])
            ->assertCreated()
            ->assertJsonPath('data.status.value', 'pending_payment')
            ->assertJsonPath('data.amount_paise', 40000)
            ->assertJsonPath('data.code', null)
            ->assertJsonPath('checkout.payment.amount_paise', 40000);

        $payment = Payment::query()->where('uuid', $response->json('checkout.payment.id'))->firstOrFail();
        $this->assertSame(Payment::EVENT_TICKET, $payment->purpose);
        app(Payments::class)->apply($payment, Payment::PAID, 'pay_T1');

        $ref = $response->json('data.reference');
        $ticket = EventRegistration::query()->where('reference', $ref)->firstOrFail();
        $this->assertSame(BookingStatus::Confirmed, $ticket->status);

        $this->getJson('/api/v1/me/event-tickets')->assertOk()->assertJsonPath('data.0.reference', $ref);

        // The temple's counter scans it: received once, refused the second time.
        $team = User::factory()->create(['role' => UserRole::TempleAdmin, 'is_active' => true]);
        TempleUser::create(['temple_id' => $this->temple->id, 'user_id' => $team->id, 'role' => 'manager', 'requested_at' => now(), 'approved_at' => now()]);
        $this->app['auth']->forgetGuards();
        $this->withToken($team->createToken('trust')->plainTextToken);

        $this->postJson('/api/v1/trust/bookings/scan', ['code' => $ticket->qrUrl()])
            ->assertOk()
            ->assertJsonPath('data.booking.kind', 'event')
            ->assertJsonPath('data.booking.event.title', 'Music evening');
        $this->postJson('/api/v1/trust/bookings/verify', ['code' => $ref])->assertOk()->assertJsonPath('data.outcome', 'verified');
        $this->postJson('/api/v1/trust/bookings/verify', ['code' => $ref])->assertOk()->assertJsonPath('data.outcome', 'already_verified');

        $this->getJson('/api/v1/trust/temples/'.$this->temple->id.'/events/'.$event->id.'/registrations')
            ->assertOk()
            ->assertJsonPath('data.summary.people', 2)
            ->assertJsonPath('data.summary.received', 1)
            ->assertJsonPath('data.summary.amount_paise', 40000);

        // An event that sold tickets cannot be deleted with them.
        $this->deleteJson('/api/v1/trust/temples/'.$this->temple->id.'/events/'.$event->id)->assertUnprocessable();
        $this->assertNotNull($event->fresh());
    }

    public function test_the_hundi_takes_gifts_only_when_the_temple_switched_it_on(): void
    {
        $this->devotee();

        $this->postJson('/api/v1/temples/sri-rama/donations', ['amount' => 501, 'platform' => 'android'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('temple');

        Temple::query()->whereKey($this->temple->id)->update(['accepts_donations' => true]);
        $this->getJson('/api/v1/temples/sri-rama')->assertJsonPath('data.donations.enabled', true);

        $this->postJson('/api/v1/temples/sri-rama/donations', ['amount' => 5, 'platform' => 'android'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');

        $response = $this->postJson('/api/v1/temples/sri-rama/donations', [
            'amount' => 501, 'purpose' => 'annadanam', 'is_anonymous' => true, 'platform' => 'android',
        ])->assertCreated()
            ->assertJsonPath('data.amount_paise', 50100)
            ->assertJsonPath('data.status.value', 'pending_payment')
            ->assertJsonPath('checkout.payment.purpose', 'donation');

        app(Payments::class)->apply(Payment::query()->where('uuid', $response->json('checkout.payment.id'))->firstOrFail(), Payment::PAID, 'pay_H1');

        $this->getJson('/api/v1/me/donations/'.$response->json('data.reference'))
            ->assertOk()
            ->assertJsonPath('data.status.value', 'paid')
            ->assertJsonPath('data.purpose.value', 'annadanam');

        // The temple sees the gift, not the giver.
        $owner = User::factory()->create(['role' => UserRole::TempleAdmin, 'is_active' => true]);
        TempleUser::create(['temple_id' => $this->temple->id, 'user_id' => $owner->id, 'role' => 'owner', 'requested_at' => now(), 'approved_at' => now()]);
        $this->app['auth']->forgetGuards();
        $this->withToken($owner->createToken('trust')->plainTextToken);

        $this->getJson('/api/v1/trust/temples/'.$this->temple->id.'/donations')
            ->assertOk()
            ->assertJsonPath('data.today.amount_paise', 50100)
            ->assertJsonPath('data.items.0.donor', 'A devotee')
            ->assertJsonPath('data.can_change', true);

        $this->putJson('/api/v1/trust/temples/'.$this->temple->id.'/donation-settings', ['accepts_donations' => false])
            ->assertOk()
            ->assertJsonPath('data.accepts_donations', false);
    }

    public function test_one_settlement_pays_sevas_tickets_and_gifts_with_their_own_fees(): void
    {
        Setting::set('finance_platform_fee_percent', '2');
        Setting::set('finance_donation_fee_percent', '0');
        $devotee = Devotee::factory()->create();
        $yesterday = DevotionalClock::now()->subDay()->toDateString();

        $ticketPayment = Payment::create(['devotee_id' => $devotee->id, 'purpose' => Payment::EVENT_TICKET, 'gateway' => 'razorpay', 'amount_paise' => 10000, 'status' => Payment::PAID, 'paid_at' => now()]);
        $event = $this->event(['ticket_price_paise' => 10000]);
        EventRegistration::create([
            'temple_event_id' => $event->id, 'temple_id' => $this->temple->id, 'devotee_id' => $devotee->id, 'payment_id' => $ticketPayment->id,
            'occurs_on' => $yesterday, 'people' => 1, 'devotee_name' => 'Anu', 'amount_paise' => 10000, 'status' => BookingStatus::Expired,
        ]);

        $giftPayment = Payment::create(['devotee_id' => $devotee->id, 'purpose' => Payment::DONATION, 'gateway' => 'razorpay', 'amount_paise' => 50000, 'status' => Payment::PAID, 'paid_at' => now()]);
        $gift = TempleDonation::create(['temple_id' => $this->temple->id, 'devotee_id' => $devotee->id, 'payment_id' => $giftPayment->id, 'amount_paise' => 50000]);
        $gift->forceFill(['status' => TempleDonation::PAID, 'paid_at' => now(), 'paid_on' => $yesterday])->save();

        $s = app(Settlements::class)->create($this->temple, null, null);

        $this->assertSame(1, $s->tickets_count);
        $this->assertSame(1, $s->donations_count);
        $this->assertSame(60000, $s->gross_paise);
        // 2% of the ticket, nothing of the gift.
        $this->assertSame(200, $s->fee_paise);
        $this->assertSame(59800, $s->net_paise);
        $this->assertSame($s->id, $gift->refresh()->settlement_id);

        app(Settlements::class)->cancel($s, 'Redo');
        $this->assertNull($gift->refresh()->settlement_id);
    }

    public function test_the_admin_finance_pages_list_tickets_and_hundi(): void
    {
        $event = $this->event(['ticket_price_paise' => 10000]);
        $devotee = Devotee::factory()->create();
        EventRegistration::create([
            'temple_event_id' => $event->id, 'temple_id' => $this->temple->id, 'devotee_id' => $devotee->id,
            'occurs_on' => DevotionalClock::now()->toDateString(), 'people' => 1, 'devotee_name' => 'Ravi Kumar', 'amount_paise' => 10000, 'status' => BookingStatus::Confirmed,
        ]);
        $gift = TempleDonation::create(['temple_id' => $this->temple->id, 'devotee_id' => $devotee->id, 'amount_paise' => 50100, 'donor_name' => 'Lakshmi Devi']);
        $gift->forceFill(['status' => TempleDonation::PAID, 'paid_at' => now(), 'paid_on' => DevotionalClock::now()->toDateString()])->save();

        $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin, 'is_active' => true]), 'web');

        $this->get('/admin/finance/event-tickets')->assertOk()->assertSee('Ravi Kumar');
        $this->get('/admin/finance/hundi')->assertOk()->assertSee('Lakshmi Devi');
        $this->get('/admin/finance/temple-balances')->assertOk()->assertSee('Sri Rama Temple');
        $this->get('/admin/temple-events')->assertOk();
        $this->get('/admin/temple-events/'.$event->id.'/edit')->assertOk()->assertSee('Gathering and tickets');
    }
}
