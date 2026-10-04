<?php

namespace Tests\Feature\Temple;

use App\Enums\BookingStatus;
use App\Enums\EventStatus;
use App\Enums\TempleStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Temples\RelationManagers\PujasRelationManager;
use App\Filament\Temple\Pages\BankDetails;
use App\Filament\Temple\Pages\Dashboard;
use App\Filament\Temple\Pages\Finance;
use App\Filament\Temple\Pages\TempleQrCode;
use App\Filament\Temple\Resources\EventTickets\Pages\ListEventTickets;
use App\Filament\Temple\Resources\HundiDonations\Pages\ListHundiDonations;
use App\Filament\Temple\Resources\MyTemples\Pages\EditMyTemple;
use App\Filament\Temple\Resources\SupportTickets\Pages\ListSupportTickets;
use App\Filament\Temple\Resources\SupportTickets\Pages\ViewSupportTicket;
use App\Filament\Temple\Resources\SupportTickets\SupportTicketResource;
use App\Filament\Temple\TemplePortal;
use App\Filament\Temple\Widgets\EarningsChartWidget;
use App\Filament\Temple\Widgets\ManageWidget;
use App\Filament\Temple\Widgets\TempleStatusWidget;
use App\Filament\Temple\Widgets\TodaysBookingsWidget;
use App\Filament\Temple\Widgets\TodayStatsWidget;
use App\Models\Devotee;
use App\Models\EventRegistration;
use App\Models\Payment;
use App\Models\PujaBooking;
use App\Models\SupportTicket;
use App\Models\Temple;
use App\Models\TempleDonation;
use App\Models\TempleEvent;
use App\Models\TemplePuja;
use App\Models\TempleUser;
use App\Models\User;
use App\Support\DevotionalClock;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The temple portal does what the trust app does: a dashboard, finance,
 * bank details and verification, the online hundi, event attendees, the
 * check-in QR and support. Each part sees only the team's own temples, and
 * only the owner changes where money goes.
 */
class TemplePortalFeaturesTest extends TestCase
{
    use RefreshDatabase;

    private Temple $temple;

    private Temple $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temple = Temple::create(['name' => 'Sri Rama Temple', 'city' => 'Bhadrachalam', 'status' => TempleStatus::Published, 'published_at' => now()]);
        $this->other = Temple::create(['name' => 'Their Temple', 'status' => TempleStatus::Published, 'published_at' => now()]);
    }

    private function team(string $role = 'owner', ?Temple $temple = null): User
    {
        $user = User::factory()->create(['role' => UserRole::TempleAdmin, 'is_active' => true]);
        TempleUser::create(['temple_id' => ($temple ?? $this->temple)->id, 'user_id' => $user->id, 'role' => $role, 'requested_at' => now(), 'approved_at' => now()]);
        $this->actingAs($user);
        Filament::setCurrentPanel('temple');

        return $user;
    }

    private function paidBooking(Temple $temple): PujaBooking
    {
        $puja = TemplePuja::create(['temple_id' => $temple->id, 'name' => 'Archana', 'is_free' => false, 'app_booking_enabled' => true, 'is_published' => true]);
        $devotee = Devotee::factory()->create();
        $payment = Payment::create(['devotee_id' => $devotee->id, 'purpose' => Payment::PUJA_BOOKING, 'gateway' => 'razorpay', 'amount_paise' => 25000, 'status' => Payment::PAID, 'paid_at' => now()]);

        return PujaBooking::create([
            'temple_id' => $temple->id, 'temple_puja_id' => $puja->id, 'devotee_id' => $devotee->id, 'payment_id' => $payment->id,
            'booked_for' => DevotionalClock::now()->toDateString(), 'people' => 2, 'devotee_name' => 'Lakshmi',
            'amount_paise' => 25000, 'status' => BookingStatus::Confirmed, 'confirmed_at' => now(),
        ]);
    }

    private function gift(Temple $temple, array $extra = []): TempleDonation
    {
        $devotee = Devotee::factory()->create(['name' => 'Ravi Kumar']);
        $gift = TempleDonation::create($extra + ['temple_id' => $temple->id, 'devotee_id' => $devotee->id, 'amount_paise' => 50100, 'donor_name' => 'Ravi Kumar']);
        $gift->forceFill(['status' => TempleDonation::PAID, 'paid_at' => now(), 'paid_on' => DevotionalClock::now()->toDateString()])->save();

        return $gift;
    }

    // --- Dashboard ---

    public function test_the_dashboard_shows_the_temple_today_and_every_way_in(): void
    {
        $this->team();
        $this->paidBooking($this->temple);
        $this->gift($this->temple);

        $this->get('/temple')->assertOk()->assertSee('Sri Rama Temple');

        Livewire::test(TempleStatusWidget::class)->assertSee('Sri Rama Temple')->assertSee('Online payments')->assertSee('Set up payments');
        Livewire::test(TodayStatsWidget::class)->assertSee('₹250.00')->assertSee('₹501.00')->assertSee('Reviews to answer');
        Livewire::test(EarningsChartWidget::class)->assertOk();
        Livewire::test(TodaysBookingsWidget::class)->assertSee('Lakshmi')->assertSee('Archana');
        Livewire::test(ManageWidget::class)->assertSee('Bank &amp; verification', false)->assertSee('QR poster')->assertSee('Event tickets');
    }

    public function test_a_team_with_several_temples_picks_one_and_never_another_teams(): void
    {
        $user = $this->team();
        $second = Temple::create(['name' => 'Second Temple', 'status' => TempleStatus::Draft]);
        TempleUser::create(['temple_id' => $second->id, 'user_id' => $user->id, 'role' => 'manager', 'requested_at' => now(), 'approved_at' => now()]);

        $this->assertSame([$second->id => 'Second Temple', $this->temple->id => 'Sri Rama Temple · Bhadrachalam'], TemplePortal::options());
        $this->assertSame($second->id, TemplePortal::choose($second->id)->id);
        // Another team's temple is never chosen.
        $this->assertSame($second->id, TemplePortal::choose($this->other->id)->id);

        Livewire::test(Dashboard::class, ['filters' => ['temple' => $this->other->id]])->assertSet('filters.temple', $second->id);
        Livewire::test(Finance::class)->set('templeId', $this->other->id)->assertSet('templeId', $second->id)->assertDontSee('Their Temple');
    }

    public function test_an_account_with_no_approved_temple_gets_no_temple_pages(): void
    {
        $user = User::factory()->create(['role' => UserRole::TempleAdmin, 'is_active' => true]);
        $this->actingAs($user);
        Filament::setCurrentPanel('temple');

        $this->get('/temple')->assertOk();
        $this->get(Finance::getUrl())->assertForbidden();
        $this->assertFalse(TempleStatusWidget::canView());
    }

    // --- Finance ---

    public function test_finance_shows_the_day_the_periods_and_the_balance(): void
    {
        $this->team('manager');
        $this->paidBooking($this->temple);
        $this->gift($this->temple);
        $this->gift($this->other);

        $this->get(Finance::getUrl())->assertOk()
            ->assertSee('Total paid')->assertSee('₹751.00')
            ->assertSee('This month')->assertSee('All time')
            ->assertSee('Ready to pay out')->assertSee('Recent payouts');
    }

    // --- Bank details and verification ---

    public function test_the_owner_adds_the_bank_and_sends_the_documents_for_approval(): void
    {
        Storage::fake('local');
        config(['filesystems.disks.spaces.bucket' => null]);
        $owner = $this->team('owner');

        Livewire::test(BankDetails::class)
            ->set('bank.account_name', 'Sri Rama Temple Trust')
            ->set('bank.account_number', '123456789012')
            ->set('bank.ifsc', 'sbin0001234')
            ->call('saveBank')
            ->assertHasNoErrors();

        $account = $this->temple->payoutAccount()->firstOrFail();
        $this->assertSame('SBIN0001234', $account->ifsc);
        $this->assertSame('123456789012', $account->account_number);
        $this->assertFalse($account->isVerified());

        Livewire::test(BankDetails::class)
            ->set('kyc.kyc_name', 'Trust Secretary')
            ->set('kyc.aadhaar_number', '2345 6789 0123')
            ->set('kyc.temple_proof_kind', 'trust_registration')
            ->set('kyc.aadhaar_front', [UploadedFile::fake()->image('front.jpg')])
            ->set('kyc.aadhaar_back', [UploadedFile::fake()->image('back.jpg')])
            ->set('kyc.person_photo', [UploadedFile::fake()->image('me.jpg')])
            ->set('kyc.temple_proof', [UploadedFile::fake()->create('proof.pdf', 100, 'application/pdf')])
            ->call('sendKyc')
            ->assertHasNoErrors();

        $account->refresh();
        $this->assertSame('pending', $account->kycStatus());
        $this->assertSame('0123', $account->aadhaar_last4);
        Storage::disk($account->kyc_disk)->assertExists($account->aadhaar_front_path);
        $this->assertStringStartsWith('kyc/'.$this->temple->id.'/', $account->temple_proof_path);
        $this->assertSame($owner->id, $account->verificationEvents()->first()->user_id);
    }

    public function test_only_the_owner_changes_where_money_goes(): void
    {
        $this->team('manager');

        $this->get(BankDetails::getUrl())->assertOk()->assertSee('Only the temple');
        Livewire::test(BankDetails::class)
            ->set('bank.account_name', 'Someone Else')
            ->set('bank.account_number', '999999999999')
            ->set('bank.ifsc', 'HDFC0001234')
            ->call('saveBank')
            ->assertForbidden();

        $this->assertNull($this->temple->payoutAccount()->first());
    }

    // --- Online hundi ---

    public function test_the_hundi_lists_the_temples_gifts_and_keeps_anonymous_givers_anonymous(): void
    {
        $this->team('manager');
        $named = $this->gift($this->temple);
        $anon = $this->gift($this->temple, ['is_anonymous' => true, 'donor_name' => 'Secret Giver']);
        $elsewhere = $this->gift($this->other);

        Livewire::test(ListHundiDonations::class)
            ->assertCanSeeTableRecords([$named, $anon])
            ->assertCanNotSeeTableRecords([$elsewhere])
            ->assertDontSee('Secret Giver')
            ->searchTable('Secret')
            ->assertCanNotSeeTableRecords([$anon])
            ->searchTable($anon->reference)
            ->assertCanSeeTableRecords([$anon]);
    }

    public function test_only_the_owner_switches_the_hundi_and_only_once_payments_are_approved(): void
    {
        $this->team('manager');
        Livewire::test(ListHundiDonations::class)->assertActionHidden('toggleHundi');

        $this->team('owner');
        Livewire::test(ListHundiDonations::class)->callAction('toggleHundi');
        $this->assertFalse($this->temple->fresh()->accepts_donations, 'not before the bank and documents are approved');

        $this->approvePayments($this->temple);
        Livewire::test(ListHundiDonations::class)->callAction('toggleHundi');
        $this->assertTrue($this->temple->fresh()->accepts_donations);
    }

    // --- Event attendees ---

    public function test_event_attendees_are_listed_and_received_at_the_temple_only(): void
    {
        $this->team('manager');
        $event = TempleEvent::create(['temple_id' => $this->temple->id, 'title' => 'Ekadashi Bhajan', 'type' => 'bhajan', 'starts_on' => now()->toDateString(), 'status' => EventStatus::Published, 'registration_enabled' => true]);
        $mine = EventRegistration::create([
            'temple_event_id' => $event->id, 'temple_id' => $this->temple->id, 'devotee_id' => Devotee::factory()->create()->id,
            'occurs_on' => DevotionalClock::now()->toDateString(), 'people' => 3, 'devotee_name' => 'Anu', 'amount_paise' => 0, 'status' => BookingStatus::Confirmed,
        ]);
        $theirEvent = TempleEvent::create(['temple_id' => $this->other->id, 'title' => 'Elsewhere', 'type' => 'bhajan', 'starts_on' => now()->toDateString(), 'status' => EventStatus::Published, 'registration_enabled' => true]);
        $theirs = EventRegistration::create([
            'temple_event_id' => $theirEvent->id, 'temple_id' => $this->other->id, 'devotee_id' => Devotee::factory()->create()->id,
            'occurs_on' => DevotionalClock::now()->toDateString(), 'people' => 1, 'devotee_name' => 'Other', 'amount_paise' => 0, 'status' => BookingStatus::Confirmed,
        ]);

        Livewire::test(ListEventTickets::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs])
            ->callTableAction('receive', $mine);

        $this->assertSame(BookingStatus::Verified, $mine->fresh()->status);
    }

    // --- QR and support ---

    public function test_the_check_in_qr_poster_is_one_click_away(): void
    {
        $this->team('manager');

        $this->get(TempleQrCode::getUrl())->assertOk()
            ->assertSee('Check in with Darshan Saathi')
            ->assertSee(route('temples.qr.print', $this->temple), false)
            ->assertSee('/temples/'.$this->temple->slug.'/checkin?s=', false);
    }

    public function test_support_requests_are_sent_answered_and_private(): void
    {
        $user = $this->team('manager');

        Livewire::test(ListSupportTickets::class)
            ->callAction('new', ['temple_id' => $this->temple->id, 'category' => 'other', 'subject' => 'Change our timings photo', 'body' => 'Please replace the cover.']);
        $ticket = SupportTicket::where('user_id', $user->id)->sole();
        $this->assertSame($this->temple->id, $ticket->about_id);

        Livewire::test(ViewSupportTicket::class, ['record' => $ticket->reference])
            ->assertSee('Please replace the cover.')
            ->callAction('reply', ['body' => 'Any update?']);
        $this->assertSame(1, $ticket->replies()->count());

        // Another team member's request is not theirs to read.
        $this->team('manager', $this->other);
        $this->get(SupportTicketResource::getUrl('view', ['record' => $ticket]))->assertNotFound();
    }

    // --- The trust app's money rules apply in the portal too ---

    public function test_a_team_cannot_take_money_for_a_seva_before_payments_are_approved(): void
    {
        $this->team('manager');

        Livewire::test(PujasRelationManager::class, ['ownerRecord' => $this->temple, 'pageClass' => EditMyTemple::class])
            ->callTableAction('create', data: ['name' => 'Abhishekam', 'kind' => 'seva', 'is_free' => false, 'fee_amount' => 500, 'app_booking_enabled' => true]);

        $this->assertNull(TemplePuja::where('name', 'Abhishekam')->where('app_booking_enabled', true)->first());
    }
}
