<?php

namespace Tests\Feature\Filament;

use App\Enums\BookingStatus;
use App\Enums\EventStatus;
use App\Enums\TempleStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Devotees\Pages\ListDevotees;
use App\Filament\Resources\EventTickets\Pages\ListEventTickets;
use App\Filament\Resources\HundiDonations\Pages\ListHundiDonations;
use App\Filament\Resources\PujaBookings\Pages\ListPujaBookings;
use App\Models\Devotee;
use App\Models\EventRegistration;
use App\Models\PujaBooking;
use App\Models\Temple;
use App\Models\TempleDonation;
use App\Models\TempleEvent;
use App\Models\TemplePuja;
use App\Models\User;
use App\Support\DevotionalClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** The admin lists find a person by name or phone, however the number was saved. */
class AdminPersonSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_bookings_tickets_and_gifts_are_found_by_name_or_phone(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin, 'is_active' => true]));
        $temple = Temple::create(['name' => 'Sri Rama Temple', 'slug' => 'sri-rama', 'status' => TempleStatus::Published, 'published_at' => now()]);
        $lakshmi = Devotee::factory()->create(['name' => 'Lakshmi Devi', 'phone' => '+91 98480 22338']);
        $ravi = Devotee::factory()->create(['name' => 'Ravi Kumar', 'phone' => '90000 11111']);
        $today = DevotionalClock::now()->toDateString();

        $puja = TemplePuja::create(['temple_id' => $temple->id, 'name' => 'Archana', 'is_free' => true, 'app_booking_enabled' => true, 'is_published' => true]);
        $booking = fn (Devotee $d, ?string $phone) => PujaBooking::create([
            'temple_id' => $temple->id, 'temple_puja_id' => $puja->id, 'devotee_id' => $d->id, 'booked_for' => $today,
            'people' => 1, 'devotee_name' => $d->name, 'devotee_phone' => $phone, 'amount_paise' => 0,
            'status' => BookingStatus::Confirmed, 'confirmed_at' => now(),
        ]);
        $b1 = $booking($lakshmi, null);
        $b2 = $booking($ravi, '90000-11111');

        Livewire::test(ListPujaBookings::class)->searchTable('9848022338')->assertCanSeeTableRecords([$b1])->assertCanNotSeeTableRecords([$b2]);
        Livewire::test(ListPujaBookings::class)->searchTable('11111')->assertCanSeeTableRecords([$b2])->assertCanNotSeeTableRecords([$b1]);
        Livewire::test(ListPujaBookings::class)->searchTable('lakshmi')->assertCanSeeTableRecords([$b1])->assertCanNotSeeTableRecords([$b2]);
        Livewire::test(ListPujaBookings::class)->searchTable($b2->reference)->assertCanSeeTableRecords([$b2])->assertCanNotSeeTableRecords([$b1]);

        $event = TempleEvent::create(['temple_id' => $temple->id, 'type' => 'bhajan', 'title' => 'Saturday bhajan', 'starts_on' => $today, 'is_all_day' => true, 'status' => EventStatus::Published, 'registration_enabled' => true]);
        $ticket = fn (Devotee $d, ?string $phone) => EventRegistration::create([
            'temple_event_id' => $event->id, 'temple_id' => $temple->id, 'devotee_id' => $d->id, 'occurs_on' => $today,
            'people' => 1, 'devotee_name' => $d->name, 'devotee_phone' => $phone, 'amount_paise' => 0, 'status' => BookingStatus::Confirmed,
        ]);
        $t1 = $ticket($lakshmi, '98480 22338');
        $t2 = $ticket($ravi, null);
        Livewire::test(ListEventTickets::class)->searchTable('98480-22338')->assertCanSeeTableRecords([$t1])->assertCanNotSeeTableRecords([$t2]);
        Livewire::test(ListEventTickets::class)->searchTable('90000 11111')->assertCanSeeTableRecords([$t2])->assertCanNotSeeTableRecords([$t1]);

        $gift = function (Devotee $d) use ($temple): TempleDonation {
            $g = new TempleDonation(['temple_id' => $temple->id, 'devotee_id' => $d->id, 'amount_paise' => 50100, 'purpose' => 'general', 'donor_name' => $d->name, 'is_anonymous' => false, 'status' => TempleDonation::PAID]);
            $g->forceFill(['paid_at' => now(), 'paid_on' => now()->toDateString()])->save();

            return $g;
        };
        $g1 = $gift($lakshmi);
        $g2 = $gift($ravi);
        Livewire::test(ListHundiDonations::class)->searchTable('22338')->assertCanSeeTableRecords([$g1])->assertCanNotSeeTableRecords([$g2]);
        Livewire::test(ListHundiDonations::class)->searchTable('ravi')->assertCanSeeTableRecords([$g2])->assertCanNotSeeTableRecords([$g1]);
    }

    public function test_devotee_accounts_are_found_by_name_email_or_phone(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin, 'is_active' => true]));
        $lakshmi = Devotee::factory()->create(['name' => 'Lakshmi Devi', 'email' => 'lakshmi@example.org', 'phone' => '+91 98480 22338']);
        $ravi = Devotee::factory()->create(['name' => 'Ravi Kumar', 'email' => 'ravi@example.org', 'phone' => '90000 11111']);

        $list = ListDevotees::class;
        Livewire::test($list)->searchTable('9848022338')->assertCanSeeTableRecords([$lakshmi])->assertCanNotSeeTableRecords([$ravi]);
        Livewire::test($list)->searchTable('ravi@')->assertCanSeeTableRecords([$ravi])->assertCanNotSeeTableRecords([$lakshmi]);
        Livewire::test($list)->searchTable('Lakshmi')->assertCanSeeTableRecords([$lakshmi])->assertCanNotSeeTableRecords([$ravi]);
    }
}
