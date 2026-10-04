<?php

namespace Tests\Feature\Security;

use App\Enums\BookingStatus;
use App\Enums\EventStatus;
use App\Enums\TempleStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Devotees\Pages\ListDevotees;
use App\Filament\Resources\PujaBookings\Pages\ListPujaBookings;
use App\Filament\Resources\TemplePujas\Pages\ListTemplePujas;
use App\Filament\Resources\Temples\Pages\EditTemple;
use App\Filament\Resources\Temples\RelationManagers\PhotosRelationManager;
use App\Filament\Temple\Resources\MyTemples\Pages\EditMyTemple;
use App\Models\Devotee;
use App\Models\Payment;
use App\Models\PujaBooking;
use App\Models\Temple;
use App\Models\TempleDonation;
use App\Models\TempleEvent;
use App\Models\TemplePhoto;
use App\Models\TemplePuja;
use App\Models\TempleUser;
use App\Models\User;
use App\Support\DevotionalClock;
use App\Support\Http\CappedSink;
use App\Support\Http\PublicAddress;
use App\Support\OfficialSite\OfficialSiteReader;
use App\Support\Payments\Payments;
use App\Support\TempleImport\MapsLink;
use App\Support\TrustedProxies;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * One check per finding of the security audit, so none comes back: each
 * reproduces the attack and expects it to fail.
 */
class AuditFixesTest extends TestCase
{
    use RefreshDatabase;

    private function temple(array $extra = []): Temple
    {
        return Temple::create($extra + ['name' => 'Sri Rama Temple '.uniqid(), 'status' => TempleStatus::Published, 'published_at' => now()]);
    }

    private function staff(UserRole $role): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    private function paidBooking(Temple $temple, ?TemplePuja $puja = null): PujaBooking
    {
        $puja ??= TemplePuja::create(['temple_id' => $temple->id, 'name' => 'Archana', 'is_free' => false, 'price_paise' => 50000, 'app_booking_enabled' => true, 'is_published' => true]);
        $devotee = Devotee::factory()->create();
        $payment = Payment::create(['devotee_id' => $devotee->id, 'purpose' => Payment::PUJA_BOOKING, 'gateway' => 'razorpay', 'amount_paise' => 50000, 'status' => Payment::PAID, 'paid_at' => now()]);

        return PujaBooking::create([
            'temple_id' => $temple->id, 'temple_puja_id' => $puja->id, 'devotee_id' => $devotee->id, 'payment_id' => $payment->id,
            'booked_for' => DevotionalClock::now()->toDateString(), 'people' => 1, 'devotee_name' => 'Lakshmi',
            'amount_paise' => 50000, 'status' => BookingStatus::Confirmed, 'confirmed_at' => now(),
        ]);
    }

    // --- A refunded payment stays refunded ---

    public function test_a_replayed_or_late_paid_callback_cannot_revive_a_refunded_offering(): void
    {
        $temple = $this->temple();
        $devotee = Devotee::factory()->create();
        $payment = Payment::create(['devotee_id' => $devotee->id, 'purpose' => Payment::DONATION, 'gateway' => 'payu', 'amount_paise' => 50000, 'status' => Payment::PAID, 'paid_at' => now()]);
        $gift = TempleDonation::create(['temple_id' => $temple->id, 'devotee_id' => $devotee->id, 'payment_id' => $payment->id, 'amount_paise' => 50000]);
        $gift->forceFill(['status' => TempleDonation::PAID, 'paid_at' => now()])->save();

        $payments = app(Payments::class);
        $payments->refunded($payment);
        // What a replayed gateway webhook does.
        $payments->apply($payment->fresh(), Payment::PAID, 'pay_replayed');

        $this->assertSame(Payment::REFUNDED, $payment->fresh()->status);
        $this->assertSame(TempleDonation::REFUNDED, $gift->fresh()->status);
    }

    // --- A suspended devotee is signed out ---

    public function test_suspending_a_devotee_ends_their_sign_ins_at_once(): void
    {
        $devotee = Devotee::factory()->create(['is_active' => true]);
        $token = $devotee->createToken('app')->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/me')->assertOk();

        $this->actingAs($this->staff(UserRole::SuperAdmin));
        Livewire::test(ListDevotees::class)->callTableAction('deactivate', $devotee)->assertHasNoTableActionErrors();

        $this->assertSame(0, $devotee->tokens()->count());
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_a_token_of_an_inactive_devotee_is_refused_however_it_was_deactivated(): void
    {
        $devotee = Devotee::factory()->create(['is_active' => true]);
        $token = $devotee->createToken('app')->plainTextToken;
        // Straight in the database, so no hook revokes anything.
        Devotee::whereKey($devotee->id)->update(['is_active' => false]);

        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    }

    // --- Upload fields keep only their own files ---

    public function test_a_temple_team_cannot_save_or_delete_another_temples_file(): void
    {
        Storage::fake('public');
        config(['filesystems.media' => 'public']);
        $mine = $this->temple();
        $theirs = $this->temple();
        Storage::disk('public')->put('temples/'.$theirs->id.'/theirs.jpg', 'their photo');

        $admin = $this->staff(UserRole::TempleAdmin);
        TempleUser::create(['temple_id' => $mine->id, 'user_id' => $admin->id, 'requested_at' => now(), 'approved_at' => now()]);
        $this->actingAs($admin);
        Filament::setCurrentPanel('temple');

        Livewire::test(PhotosRelationManager::class, ['ownerRecord' => $mine, 'pageClass' => EditMyTemple::class])
            ->callTableAction('create', data: ['path' => ['temples/'.$theirs->id.'/theirs.jpg'], 'category' => 'gallery'])
            ->assertHasTableActionErrors(['path']);
        $this->assertSame(0, $mine->photos()->count());

        // A row that names the file anyway (an old one, an import) does not
        // take it along when deleted.
        $row = TemplePhoto::withoutEvents(fn () => TemplePhoto::create(['temple_id' => $mine->id, 'disk' => 'public', 'path' => 'temples/'.$theirs->id.'/theirs.jpg']));
        $row->delete();
        Storage::disk('public')->assertExists('temples/'.$theirs->id.'/theirs.jpg');
    }

    // --- Money records and editors ---

    public function test_an_editor_cannot_mark_a_paid_booking_refunded(): void
    {
        $booking = $this->paidBooking($this->temple());

        $this->actingAs($this->staff(UserRole::Editor));
        Livewire::test(ListPujaBookings::class)->assertTableActionHidden('refunded', $booking);
        $this->assertSame(Payment::PAID, $booking->payment->fresh()->status);

        $this->actingAs($this->staff(UserRole::SuperAdmin));
        Livewire::test(ListPujaBookings::class)->assertTableActionVisible('refunded', $booking);
    }

    public function test_a_booked_seva_is_never_deleted_with_its_bookings(): void
    {
        $temple = $this->temple();
        $booking = $this->paidBooking($temple);

        $this->actingAs($this->staff(UserRole::Editor));
        try {
            Livewire::test(ListTemplePujas::class)->callTableAction('delete', $booking->puja);
        } catch (ValidationException) {
            // Refused, as it should be.
        }

        $this->assertModelExists($booking->puja);
        $this->assertModelExists($booking);

        $this->expectException(ValidationException::class);
        $booking->puja->delete();
    }

    public function test_a_temple_with_money_records_is_never_removed_for_good(): void
    {
        $temple = $this->temple();
        $booking = $this->paidBooking($temple);

        $this->actingAs($this->staff(UserRole::Editor));
        Livewire::test(EditTemple::class, ['record' => $temple->getRouteKey()])->assertActionHidden('forceDelete');

        $temple->delete();
        try {
            $temple->forceDelete();
            $this->fail('A temple with bookings was deleted permanently.');
        } catch (ValidationException) {
        }
        $this->assertModelExists($booking);
    }

    // --- Support reports name only what is public ---

    public function test_a_report_cannot_reveal_a_hidden_temple_event_or_seva(): void
    {
        $draft = Temple::create(['name' => 'Secret Draft Temple', 'status' => TempleStatus::Draft]);
        $published = $this->temple();
        $event = TempleEvent::create(['temple_id' => $published->id, 'title' => 'Hidden Event', 'type' => 'festival', 'starts_on' => now()->toDateString(), 'status' => EventStatus::PendingReview]);
        $seva = TemplePuja::create(['temple_id' => $published->id, 'name' => 'Hidden Seva', 'is_published' => false]);
        $report = fn (string $type, int $id) => $this->postJson('/api/v1/support', ['name' => 'x', 'subject' => 's', 'body' => 'b', 'about_type' => $type, 'about_id' => $id]);

        $report('temple', $draft->id)->assertNotFound();
        $report('event', $event->id)->assertNotFound();
        $report('puja', $seva->id)->assertNotFound();

        $report('temple', $published->id)->assertCreated()->assertJsonPath('data.about.label', 'Temple: '.$published->name);
    }

    // --- The website reader stays on the public internet ---

    public function test_the_website_reader_never_fetches_internal_addresses(): void
    {
        Http::fake(['*' => Http::response('<html><body>internal</body></html>', 200, ['Content-Type' => 'text/html'])]);
        PublicAddress::$resolveUsing = fn (string $host): array => match ($host) {
            'rebind.example' => ['127.0.0.1'],
            default => ['93.184.216.34'],
        };

        foreach (['http://127.0.0.1:8080/', 'http://169.254.169.254/latest/meta-data/', 'http://10.0.0.5/', 'http://[::1]/', 'http://rebind.example/', 'http://localhost/'] as $url) {
            $this->assertArrayHasKey('error', OfficialSiteReader::read($url), $url);
        }
        Http::assertNothingSent();
    }

    public function test_the_website_reader_does_not_follow_a_redirect_inward(): void
    {
        Http::fake([
            'temple.example/*' => Http::response('', 302, ['Location' => 'http://127.0.0.1/admin']),
            '*' => Http::response('<html><body>internal</body></html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $this->assertArrayHasKey('error', OfficialSiteReader::read('http://temple.example/'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '127.0.0.1'));
    }

    public function test_an_address_counts_as_public_only_when_it_is(): void
    {
        foreach (['127.0.0.1', '10.1.2.3', '172.16.0.1', '192.168.1.1', '169.254.169.254', '100.64.0.1', '::1', 'fc00::1', '::ffff:127.0.0.1', '0.0.0.0'] as $ip) {
            $this->assertFalse(PublicAddress::isPublic($ip), $ip);
        }
        $this->assertTrue(PublicAddress::isPublic('93.184.216.34'));
        $this->assertTrue(PublicAddress::isPublic('2606:4700::1111'));
    }

    public function test_a_download_stops_at_the_size_limit_however_well_it_compresses(): void
    {
        $sink = new CappedSink(1000);
        $sink->write(str_repeat('a', 1000));

        $this->expectException(\RuntimeException::class);
        $sink->write('b');
    }

    public function test_a_site_that_breaks_the_read_does_not_hold_up_the_others(): void
    {
        $bad = $this->temple(['official_website' => 'https://broken.example']);
        $good = $this->temple(['official_website' => 'https://fine.example']);
        Temple::whereKey($good->id)->update(['official_import_at' => now()->subDays(60)]);
        Http::fake([
            'broken.example/*' => fn () => throw new \RuntimeException('Allowed memory size exhausted'),
            '*' => Http::response('<html><head><title>Fine Temple</title></head><body>Darshan 6 AM to 8 PM</body></html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $this->artisan('temples:read-official-sites', ['--days' => 30, '--limit' => 100])->assertSuccessful();

        $this->assertNotNull($bad->fresh()->official_import_at, 'tried, so it goes to the back of the line');
        $this->assertTrue($good->fresh()->official_import_at->isToday());
    }

    public function test_only_googles_own_hosts_count_as_maps_links(): void
    {
        $this->assertTrue(MapsLink::isMapsLink('https://www.google.com/maps/place/x'));
        $this->assertTrue(MapsLink::isMapsLink('https://maps.app.goo.gl/abc'));
        $this->assertTrue(MapsLink::isMapsLink('https://www.google.co.in/maps/place/x'));
        $this->assertFalse(MapsLink::isMapsLink('https://google.g.co.attacker.example/maps'));
        $this->assertFalse(MapsLink::isMapsLink('https://www.google.com.attacker.example/maps'));
    }

    // --- Links in emails use our own host ---

    public function test_a_forwarded_host_is_never_trusted(): void
    {
        $this->assertSame(0, TrustedProxies::HEADERS & Request::HEADER_X_FORWARDED_HOST);
        $this->assertNotSame(0, TrustedProxies::HEADERS & Request::HEADER_X_FORWARDED_PROTO);

        config(['app.url' => 'https://temple.darshansaathi.com', 'brand.website' => 'https://darshansaathi.com']);
        $patterns = TrustedProxies::hosts();
        $matches = fn (string $host): bool => collect($patterns)->contains(fn (string $p): bool => (bool) preg_match('{'.$p.'}i', $host));
        $this->assertTrue($matches('darshansaathi.com'));
        $this->assertTrue($matches('temple.darshansaathi.com'));
        $this->assertTrue($matches('www.darshansaathi.com'));
        $this->assertFalse($matches('attacker.example'));
        $this->assertFalse($matches('darshansaathi.com.attacker.example'));
    }
}
