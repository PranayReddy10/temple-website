<?php

namespace Tests\Feature\Api\V1;

use App\Enums\BookingStatus;
use App\Enums\TempleStatus;
use App\Enums\UserRole;
use App\Filament\Resources\TemplePujas\Pages\EditTemplePuja;
use App\Models\Devotee;
use App\Models\PujaBooking;
use App\Models\Temple;
use App\Models\TemplePuja;
use App\Models\TempleUser;
use App\Models\User;
use App\Support\Bookings\PujaBookings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Time slots like show times, and tickets that expire after their day:
 * booked for 2 October, unused, it is Expired on 3 October.
 */
class PujaSlotsTest extends TestCase
{
    use RefreshDatabase;

    protected Temple $temple;

    protected TemplePuja $puja;

    protected function setUp(): void
    {
        parent::setUp();
        // 1 October, 08:00 in India.
        $this->travelTo(Carbon::parse('2026-10-01 08:00', 'Asia/Kolkata'));

        $this->temple = Temple::create(['name' => 'Slot Temple', 'slug' => 'slot-temple', 'status' => TempleStatus::Published, 'published_at' => now()]);
        $this->puja = TemplePuja::create(['temple_id' => $this->temple->id, 'name' => 'Abhishekam', 'is_free' => true, 'app_booking_enabled' => true, 'max_people_per_booking' => 20]);
        $this->puja->slots()->createMany([
            ['starts_at' => '07:00', 'ends_at' => '08:00', 'capacity' => 10],
            ['starts_at' => '09:00', 'ends_at' => '10:00', 'capacity' => 15],
            ['starts_at' => '18:00', 'ends_at' => '19:00', 'capacity' => null, 'days' => [0]],
        ]);
    }

    protected function devotee(): Devotee
    {
        $devotee = Devotee::factory()->create(['name' => 'Anu']);
        Sanctum::actingAs($devotee, guard: 'devotee');

        return $devotee;
    }

    protected function book(string $day, ?int $slotId, int $people = 1)
    {
        return $this->postJson('/api/v1/temples/slot-temple/pujas/'.$this->puja->id.'/bookings', array_filter([
            'booked_for' => $day, 'slot_id' => $slotId, 'people' => $people,
        ]));
    }

    public function test_the_app_sees_each_slot_with_places_left_and_which_have_started(): void
    {
        $nine = $this->puja->slots()->where('starts_at', 'like', '09:00%')->first();
        $this->devotee();
        $this->book('2026-10-01', $nine->id, 4)->assertCreated();

        // Today: 7:00 has started; 9:00 has 11 of 15 left; the Sunday slot is not on.
        $this->getJson('/api/v1/temples/slot-temple/pujas/'.$this->puja->id.'/slots?date=2026-10-01')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.label', '7:00 – 8:00 AM')
            ->assertJsonPath('data.0.started', true)
            ->assertJsonPath('data.0.bookable', false)
            ->assertJsonPath('data.1.label', '9:00 – 10:00 AM')
            ->assertJsonPath('data.1.available', 11)
            ->assertJsonPath('data.1.bookable', true);

        // Sunday 4 October: the evening slot too, without a limit.
        $this->getJson('/api/v1/temples/slot-temple/pujas/'.$this->puja->id.'/slots?date=2026-10-04')
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.2.label', '6:00 – 7:00 PM')
            ->assertJsonPath('data.2.available', null);

        $this->getJson('/api/v1/temples/slot-temple')->assertOk();
    }

    public function test_a_booking_takes_places_in_its_slot_and_shows_the_time_on_the_ticket(): void
    {
        $nine = $this->puja->slots()->where('starts_at', 'like', '09:00%')->first();
        $seven = $this->puja->slots()->where('starts_at', 'like', '07:00%')->first();
        $this->devotee();

        $this->book('2026-10-02', null)->assertUnprocessable()->assertJsonValidationErrors('slot_id');
        $this->book('2026-10-01', $seven->id)->assertUnprocessable()->assertJsonValidationErrors('slot_id');

        $this->book('2026-10-02', $nine->id, 12)->assertCreated()
            ->assertJsonPath('data.booked_for', '2026-10-02')
            ->assertJsonPath('data.slot.label', '9:00 – 10:00 AM')
            ->assertJsonPath('data.slot.starts_at', '09:00');

        // 3 left: four people do not fit, three do, then it is full.
        $this->book('2026-10-02', $nine->id, 4)->assertUnprocessable();
        $this->book('2026-10-02', $nine->id, 3)->assertCreated();
        $this->book('2026-10-02', $nine->id, 1)->assertUnprocessable();

        // The ticket keeps its time if the temple moves the slot later.
        $nine->update(['starts_at' => '09:30']);
        $this->assertSame('9:00 – 10:00 AM', PujaBooking::query()->first()->slotLabel());
    }

    public function test_an_unused_ticket_expires_after_its_day_and_cannot_be_used(): void
    {
        $nine = $this->puja->slots()->where('starts_at', 'like', '09:00%')->first();
        $this->devotee();
        $ref = $this->book('2026-10-02', $nine->id)->json('data.reference');

        $staff = User::factory()->create(['role' => UserRole::TempleAdmin, 'is_active' => true]);
        TempleUser::create(['temple_id' => $this->temple->id, 'user_id' => $staff->id, 'requested_at' => now(), 'approved_at' => now()]);
        $booking = PujaBooking::query()->where('reference', $ref)->first();

        // 1 October: a ticket for the 2nd is not valid yet.
        try {
            app(PujaBookings::class)->verify($booking, $staff);
            $this->fail('A ticket for tomorrow was taken today.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('valid on that day only', $e->errors()['status'][0]);
        }

        // 2 October: still a live ticket all day.
        $this->travelTo(Carbon::parse('2026-10-02 21:00', 'Asia/Kolkata'));
        $this->getJson('/api/v1/me/bookings/'.$ref)->assertJsonPath('data.status.value', 'confirmed')->assertJsonPath('data.is_live', true);

        // 3 October: expired, with no code to show at the counter.
        $this->travelTo(Carbon::parse('2026-10-03 00:30', 'Asia/Kolkata'));
        $this->getJson('/api/v1/me/bookings')->assertJsonPath('data.0.status.value', 'expired')
            ->assertJsonPath('data.0.status.label', 'Expired')
            ->assertJsonPath('data.0.code', null);
        try {
            app(PujaBookings::class)->verify($booking->fresh(), $staff);
            $this->fail('An expired ticket was taken.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('expired', $e->errors()['status'][0]);
        }
    }

    public function test_the_scheduler_expires_old_tickets_and_cancels_unpaid_ones(): void
    {
        $devotee = Devotee::factory()->create();
        $make = fn (string $day, BookingStatus $status) => PujaBooking::create([
            'temple_id' => $this->temple->id, 'temple_puja_id' => $this->puja->id, 'devotee_id' => $devotee->id,
            'booked_for' => $day, 'devotee_name' => 'Anu', 'status' => $status,
        ]);
        $used = $make('2026-09-29', BookingStatus::Verified);
        $missed = $make('2026-09-30', BookingStatus::Confirmed);
        $unpaid = $make('2026-09-30', BookingStatus::PendingPayment);
        $today = $make('2026-10-01', BookingStatus::Confirmed);

        $this->assertSame(2, app(PujaBookings::class)->expireOverdue());

        $this->assertSame(BookingStatus::Verified, $used->fresh()->status);
        $this->assertSame(BookingStatus::Expired, $missed->fresh()->status);
        $this->assertSame(BookingStatus::Cancelled, $unpaid->fresh()->status);
        $this->assertSame(BookingStatus::Confirmed, $today->fresh()->status);
    }

    public function test_a_seva_lists_its_open_slots_for_the_app(): void
    {
        $this->puja->slots()->delete();
        $this->assertCount(0, $this->puja->fresh()->activeSlots());

        $this->puja->slots()->create(['starts_at' => '06:00', 'ends_at' => '07:00', 'capacity' => 15]);
        $this->getJson('/api/v1/temples/slot-temple')->assertOk()
            ->assertJsonFragment(['has_slots' => true])
            ->assertJsonFragment(['label' => '6:00 – 7:00 AM']);
    }

    public function test_admins_make_slots_in_the_seva_form(): void
    {
        $this->puja->slots()->delete();
        $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin, 'is_active' => true]));

        Livewire::test(EditTemplePuja::class, ['record' => $this->puja->getRouteKey()])
            ->assertOk()
            ->callFormComponentAction('slots', 'make_slots', ['from' => '09:00', 'to' => '12:00', 'minutes' => 60, 'capacity' => 15])
            ->call('save')
            ->assertHasNoFormErrors();

        $slots = $this->puja->fresh()->slots;
        $this->assertSame(['9:00 – 10:00 AM', '10:00 – 11:00 AM', '11:00 AM – 12:00 PM'], $slots->map->label()->all());
        $this->assertSame([15, 15, 15], $slots->pluck('capacity')->all());
    }
}
