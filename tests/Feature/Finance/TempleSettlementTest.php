<?php

namespace Tests\Feature\Finance;

use App\Enums\BookingStatus;
use App\Enums\TempleStatus;
use App\Enums\UserRole;
use App\Filament\Resources\TempleBalances\Pages\ListTempleBalances;
use App\Filament\Resources\TempleSettlements\Pages\ListTempleSettlements;
use App\Models\Devotee;
use App\Models\Payment;
use App\Models\PujaBooking;
use App\Models\Setting;
use App\Models\Temple;
use App\Models\TemplePayoutAccount;
use App\Models\TemplePuja;
use App\Models\TempleSettlement;
use App\Models\TempleUser;
use App\Models\User;
use App\Support\Bookings\PujaBookings;
use App\Support\DevotionalClock;
use App\Support\Finance\Settlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Paying temples for their seva bookings: what is owed, preparing a
 * settlement, paying it, and what the temple's team sees in the trust app.
 */
class TempleSettlementTest extends TestCase
{
    use RefreshDatabase;

    protected Temple $temple;

    protected Temple $other;

    protected TemplePuja $puja;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temple = Temple::create(['name' => 'Sri Rama Temple', 'slug' => 'sri-rama', 'city' => 'Bhadrachalam', 'status' => TempleStatus::Published, 'published_at' => now()]);
        $this->other = Temple::create(['name' => 'Another Temple', 'slug' => 'another', 'status' => TempleStatus::Published, 'published_at' => now()]);
        $this->puja = TemplePuja::create(['temple_id' => $this->temple->id, 'name' => 'Archana', 'fee' => 100, 'app_booking_enabled' => true]);
    }

    /** A booking paid through the gateway, for a day relative to today. */
    protected function paidBooking(int $daysFromToday, int $rupees, ?Temple $temple = null, BookingStatus $status = BookingStatus::Confirmed): PujaBooking
    {
        $temple ??= $this->temple;
        $devotee = Devotee::factory()->create();
        $payment = Payment::create([
            'devotee_id' => $devotee->id, 'purpose' => Payment::PUJA_BOOKING, 'gateway' => 'razorpay',
            'amount_paise' => $rupees * 100, 'status' => Payment::PAID, 'paid_at' => now(),
        ]);

        $puja = $temple->is($this->temple) ? $this->puja : TemplePuja::create(['temple_id' => $temple->id, 'name' => 'Abhishekam', 'app_booking_enabled' => true]);

        return PujaBooking::create([
            'temple_id' => $temple->id, 'temple_puja_id' => $puja->id, 'devotee_id' => $devotee->id,
            'payment_id' => $payment->id,
            'booked_for' => DevotionalClock::now()->addDays($daysFromToday)->toDateString(),
            'people' => 2, 'devotee_name' => 'Anu', 'amount_paise' => $rupees * 100,
            'status' => $status,
        ]);
    }

    protected function owner(string $role = 'owner'): User
    {
        $user = User::factory()->create(['role' => UserRole::TempleAdmin, 'is_active' => true]);
        TempleUser::create(['temple_id' => $this->temple->id, 'user_id' => $user->id, 'role' => $role, 'requested_at' => now(), 'approved_at' => now()]);

        return $user;
    }

    protected function superAdmin(): User
    {
        return User::factory()->create(['role' => UserRole::SuperAdmin, 'is_active' => true]);
    }

    protected function trust(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('trust')->plainTextToken);
    }

    public function test_a_settlement_takes_paid_bookings_whose_day_has_passed_less_the_fee(): void
    {
        Setting::set('finance_platform_fee_percent', '2');

        $past = $this->paidBooking(-3, 500);
        $verified = $this->paidBooking(-1, 300, status: BookingStatus::Verified);
        $ahead = $this->paidBooking(2, 1000);
        $cancelled = $this->paidBooking(-2, 700, status: BookingStatus::Cancelled);
        $elsewhere = $this->paidBooking(-2, 900, $this->other);

        $balance = app(Settlements::class)->balance($this->temple);
        $this->assertSame(2, $balance['ready']['bookings']);
        $this->assertSame(80000, $balance['ready']['gross_paise']);
        $this->assertSame(1600, $balance['ready']['fee_paise']);
        $this->assertSame(78400, $balance['ready']['net_paise']);
        $this->assertSame(100000, $balance['upcoming']['gross_paise']);

        $s = app(Settlements::class)->create($this->temple, null, null);

        $this->assertSame(2, $s->bookings_count);
        $this->assertSame(80000, $s->gross_paise);
        $this->assertSame(1600, $s->fee_paise);
        $this->assertSame(78400, $s->net_paise);
        $this->assertSame($s->id, $past->refresh()->settlement_id);
        $this->assertSame($s->id, $verified->refresh()->settlement_id);
        $this->assertNull($ahead->refresh()->settlement_id);
        $this->assertNull($cancelled->refresh()->settlement_id);
        $this->assertNull($elsewhere->refresh()->settlement_id);

        // Nothing left: a second settlement is refused, never paid twice.
        $this->expectException(ValidationException::class);
        app(Settlements::class)->create($this->temple, null, null);
    }

    public function test_a_temple_can_have_its_own_fee(): void
    {
        Setting::set('finance_platform_fee_percent', '5');
        TemplePayoutAccount::create(['temple_id' => $this->temple->id, 'upi_id' => 'temple@sbi', 'platform_fee_percent' => 0]);
        $this->paidBooking(-1, 1000);

        $s = app(Settlements::class)->create($this->temple, null, null);

        $this->assertSame(0, $s->fee_paise);
        $this->assertSame(100000, $s->net_paise);
    }

    public function test_paying_and_cancelling_a_settlement(): void
    {
        $booking = $this->paidBooking(-1, 500);
        $service = app(Settlements::class);

        $s = $service->create($this->temple, null, null);

        // Its money is spoken for: the booking can no longer be cancelled.
        try {
            app(PujaBookings::class)->cancel($booking->refresh(), 'temple', 'Closed');
            $this->fail('A settled booking was cancelled.');
        } catch (ValidationException) {
        }

        $service->cancel($s, 'Wrong day');
        $this->assertNull($booking->refresh()->settlement_id);
        $this->assertSame(TempleSettlement::CANCELLED, $s->refresh()->status);

        $again = $service->create($this->temple, null, null);

        try {
            $service->markPaid($again, 'bank', null, null);
            $this->fail('A bank transfer was marked paid without its UTR.');
        } catch (ValidationException) {
        }

        $service->markPaid($again, 'bank', 'UTR123456', null);
        $this->assertSame(TempleSettlement::PAID, $again->refresh()->status);
        $this->assertSame('UTR123456', $again->transaction_ref);

        $this->expectException(ValidationException::class);
        $service->cancel($again, 'Too late');
    }

    public function test_the_temple_team_sees_its_money_in_the_trust_app(): void
    {
        $this->paidBooking(0, 200);
        $this->paidBooking(0, 300, status: BookingStatus::Verified);
        $this->paidBooking(-1, 500);
        $settled = app(Settlements::class)->create($this->temple, null, null);
        app(Settlements::class)->markPaid($settled, 'upi', 'UPI998877', null);

        $user = $this->owner('manager');

        $this->trust($user)->getJson('/api/v1/trust/temples/'.$this->temple->id.'/finance')
            ->assertOk()
            ->assertJsonPath('data.today.bookings', 2)
            ->assertJsonPath('data.today.people', 4)
            ->assertJsonPath('data.today.received', 1)
            ->assertJsonPath('data.today.amount_paise', 50000)
            ->assertJsonPath('data.today.by_seva.0.seva', 'Archana')
            ->assertJsonPath('data.balance.upcoming.gross_paise', 50000)
            ->assertJsonPath('data.balance.paid.net_paise', 50000)
            ->assertJsonPath('data.recent_settlements.0.transaction_ref', 'UPI998877')
            ->assertJsonPath('data.can_edit_payout_account', false)
            // The app's financial report: the year and all time hold every
            // live booking, the one already settled included.
            ->assertJsonPath('data.year.bookings', 3)
            ->assertJsonPath('data.all_time.bookings', 3)
            ->assertJsonPath('data.all_time.total_paise', 100000);

        $this->trust($user)->getJson('/api/v1/trust/temples/'.$this->temple->id.'/settlements/'.$settled->id)
            ->assertOk()
            ->assertJsonPath('data.net_paise', 50000)
            ->assertJsonCount(1, 'data.bookings');

        // The day's bookings list carries its totals.
        $this->trust($user)->getJson('/api/v1/trust/temples/'.$this->temple->id.'/bookings?date='.DevotionalClock::now()->toDateString())
            ->assertOk()
            ->assertJsonPath('summary.amount_paise', 50000);

        // Another temple's money reads as not found.
        $this->trust($user)->getJson('/api/v1/trust/temples/'.$this->other->id.'/finance')->assertNotFound();

        // A manager may not change where the money goes.
        $this->trust($user)->putJson('/api/v1/trust/temples/'.$this->temple->id.'/payout-account', ['upi_id' => 'thief@ybl'])->assertForbidden();

        // The admin queue is a super admin's only.
        $this->trust($user)->getJson('/api/v1/trust/admin/finance')->assertForbidden();
    }

    public function test_an_owner_sets_payout_details_and_a_change_needs_verifying_again(): void
    {
        $owner = $this->owner();
        $url = '/api/v1/trust/temples/'.$this->temple->id.'/payout-account';

        $this->trust($owner)->putJson($url, ['account_name' => 'Sri Rama Trust', 'account_number' => '123', 'ifsc' => 'bad'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['account_number', 'ifsc']);

        $this->trust($owner)->putJson($url, ['account_name' => 'Sri Rama Trust', 'account_number' => '001234567890', 'ifsc' => 'sbin0001234', 'bank_name' => 'SBI Bhadrachalam'])
            ->assertOk()
            ->assertJsonPath('data.account_number_masked', 'XXXX7890')
            ->assertJsonPath('data.ifsc', 'SBIN0001234')
            ->assertJsonPath('data.is_verified', false)
            ->assertJsonMissingPath('data.account_number');

        $account = $this->temple->payoutAccount()->firstOrFail();
        $this->assertSame('001234567890', $account->account_number);
        $this->assertNotSame('001234567890', \DB::table('temple_payout_accounts')->value('account_number'));

        $admin = $this->superAdmin();
        // Approval needs the owner's documents, and is given on the web where they can be seen.
        $this->trust($admin)->postJson('/api/v1/trust/admin/temples/'.$this->temple->id.'/payout-account/verify')
            ->assertUnprocessable();
        $account->forceFill(['verified_at' => now()])->saveQuietly();

        // Resending without the number keeps it, and keeps it verified.
        $this->trust($owner)->putJson($url, ['account_name' => 'Sri Rama Trust', 'ifsc' => 'SBIN0001234', 'bank_name' => 'SBI Main Branch'])
            ->assertOk()
            ->assertJsonPath('data.is_verified', true);

        // A new account number must be checked again.
        $this->trust($owner)->putJson($url, ['account_name' => 'Sri Rama Trust', 'account_number' => '999988887777', 'ifsc' => 'SBIN0001234'])
            ->assertOk()
            ->assertJsonPath('data.account_number_masked', 'XXXX7777')
            ->assertJsonPath('data.is_verified', false);
    }

    public function test_a_super_admin_settles_from_the_trust_app(): void
    {
        $this->paidBooking(-2, 400);
        $this->paidBooking(-2, 600, $this->other);
        $admin = $this->superAdmin();

        $this->trust($admin)->getJson('/api/v1/trust/admin/finance')
            ->assertOk()
            ->assertJsonCount(2, 'data.temples')
            ->assertJsonPath('data.ready_net_paise', 100000);

        $id = $this->trust($admin)->postJson('/api/v1/trust/admin/temples/'.$this->temple->id.'/settlements', ['note' => 'Week 1'])
            ->assertCreated()
            ->assertJsonPath('data.net_paise', 40000)
            ->assertJsonPath('data.status.value', 'pending')
            ->json('data.id');

        $this->trust($admin)->postJson('/api/v1/trust/admin/temples/'.$this->temple->id.'/settlements')
            ->assertUnprocessable();

        $this->trust($admin)->postJson('/api/v1/trust/admin/settlements/'.$id.'/paid', ['method' => 'bank'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('transaction_ref');

        $this->trust($admin)->postJson('/api/v1/trust/admin/settlements/'.$id.'/paid', ['method' => 'bank', 'transaction_ref' => 'SBIN52611223344'])
            ->assertOk()
            ->assertJsonPath('data.status.value', 'paid');

        $this->trust($admin)->getJson('/api/v1/trust/admin/settlements?status=paid')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_the_admin_finance_pages_render_and_settle(): void
    {
        $this->paidBooking(-1, 250);
        $admin = $this->superAdmin();
        $this->actingAs($admin);

        $this->get('/admin/finance/temple-balances')->assertOk()->assertSee('Sri Rama Temple');
        $this->get('/admin/finance/settlements')->assertOk();

        Livewire::test(ListTempleBalances::class)
            ->callTableAction('settle', $this->temple, data: ['up_to' => DevotionalClock::now()->subDay()->toDateString(), 'note' => null])
            ->assertHasNoTableActionErrors();

        $s = TempleSettlement::query()->firstOrFail();
        $this->assertSame(25000, $s->gross_paise);

        Livewire::test(ListTempleSettlements::class)
            ->callTableAction('paid', $s, data: ['method' => 'bank', 'transaction_ref' => 'UTR1', 'paid_at' => now()->toDateTimeString()])
            ->assertHasNoTableActionErrors();

        $this->assertTrue($s->refresh()->isPaid());

        // Editors do not handle money.
        $this->actingAs(User::factory()->create(['role' => UserRole::Editor, 'is_active' => true]));
        $this->get('/admin/finance/temple-balances')->assertForbidden();
    }

    public function test_the_temple_portal_lists_only_its_own_settlements(): void
    {
        $this->paidBooking(-1, 250);
        $this->paidBooking(-1, 350, $this->other);
        app(Settlements::class)->create($this->temple, null, null);
        $theirs = app(Settlements::class)->create($this->other, null, null);

        $this->actingAs($this->owner())
            ->get('/temple/settlements')
            ->assertOk()
            ->assertSee('₹250.00')
            ->assertDontSee($theirs->reference);
    }

    public function test_advance_bookings_can_be_settled_and_then_cannot_be_cancelled(): void
    {
        $today = $this->paidBooking(0, 10);
        $ahead = $this->paidBooking(5, 20);
        $admin = $this->superAdmin();

        $this->trust($admin)->getJson('/api/v1/trust/admin/finance')
            ->assertOk()
            ->assertJsonPath('data.temples.0.ready_gross_paise', 3000)
            ->assertJsonPath('data.temples.0.ahead_gross_paise', 3000);

        $this->trust($admin)->postJson('/api/v1/trust/admin/temples/'.$this->temple->id.'/settlements', ['all' => true])
            ->assertCreated()
            ->assertJsonPath('data.bookings_count', 2)
            ->assertJsonPath('data.gross_paise', 3000);

        $this->assertNotNull($today->refresh()->settlement_id);
        $this->assertNotNull($ahead->refresh()->settlement_id);

        $this->expectException(ValidationException::class);
        app(PujaBookings::class)->cancel($ahead, 'devotee', 'Cannot come');
    }

    public function test_the_admin_can_settle_a_booking_for_today(): void
    {
        $this->paidBooking(0, 10);
        $this->actingAs($this->superAdmin());

        Livewire::test(ListTempleBalances::class)
            ->assertTableActionVisible('settle', $this->temple)
            ->mountTableAction('settle', $this->temple)
            ->assertTableActionDataSet(['up_to' => DevotionalClock::now()->toDateString()])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $this->assertSame(1000, TempleSettlement::query()->firstOrFail()->gross_paise);
    }
}
