<?php

namespace Tests\Feature\Platform;

use App\Enums\EventStatus;
use App\Enums\EventType;
use App\Enums\TempleStatus;
use App\Models\AppNotification;
use App\Models\Devotee;
use App\Models\DevoteeDevice;
use App\Models\Setting;
use App\Models\Temple;
use App\Models\TempleEvent;
use App\Support\DevotionalClock;
use App\Support\Push\EventReminders;
use App\Support\Push\FcmClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * Reminders for tomorrow's festivals and events: only to followers who asked,
 * for the kind they asked about, once.
 */
class EventRemindersTest extends TestCase
{
    use RefreshDatabase;

    protected Temple $temple;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temple = Temple::create(['name' => 'Festival Temple', 'slug' => 'festival-temple', 'status' => TempleStatus::Published, 'published_at' => now()]);
    }

    protected function event(EventType $type = EventType::Festival, ?string $on = null): TempleEvent
    {
        return TempleEvent::create([
            'temple_id' => $this->temple->id, 'type' => $type, 'title' => 'Brahmotsavam', 'description' => 'Nine days of processions.',
            'starts_on' => $on ?? DevotionalClock::now()->addDay()->toDateString(), 'status' => EventStatus::Published,
        ]);
    }

    protected function follower(bool $festivals = true, bool $events = true): Devotee
    {
        $devotee = Devotee::factory()->create();
        $devotee->follows()->create(['temple_id' => $this->temple->id, 'notify_festivals' => $festivals, 'notify_events' => $events]);

        return $devotee;
    }

    public function test_a_festival_tomorrow_reminds_followers_who_asked_and_nobody_else(): void
    {
        $wants = $this->follower();
        $optedOut = $this->follower(festivals: false);
        $saver = Devotee::factory()->create();
        $saver->savedTemples()->attach($this->temple->id);
        $this->event();
        // Not tomorrow: nothing yet.
        $this->event(on: DevotionalClock::now()->addDays(3)->toDateString());

        $this->artisan('notifications:event-reminders')->expectsOutputToContain('Sent 1 reminder')->assertSuccessful();

        $n = AppNotification::query()->firstOrFail();
        $this->assertSame('temple_festival', $n->audience);
        $this->assertSame('sent', $n->status);
        $this->assertStringContainsString('Tomorrow at Festival Temple', $n->body);
        $this->assertSame($this->temple->slug, $n->link_value);

        Sanctum::actingAs($wants, guard: 'devotee');
        $this->getJson('/api/v1/notifications?platform=android')->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Brahmotsavam');
        Sanctum::actingAs($optedOut, guard: 'devotee');
        $this->getJson('/api/v1/notifications?platform=android')->assertJsonCount(0, 'data');
        Sanctum::actingAs($saver, guard: 'devotee');
        $this->getJson('/api/v1/notifications?platform=android')->assertJsonCount(0, 'data');

        // Run again: nothing goes out twice.
        $this->artisan('notifications:event-reminders')->expectsOutputToContain('Sent 0 reminder');
        $this->assertSame(1, AppNotification::query()->count());
    }

    public function test_a_program_honours_the_event_switch_not_the_festival_one(): void
    {
        $festivalsOnly = $this->follower(festivals: true, events: false);
        $this->follower(festivals: false, events: true);
        $this->event(EventType::Program);

        $this->assertSame(1, app(EventReminders::class)->sendDue());

        $this->assertSame('temple_event', AppNotification::query()->firstOrFail()->audience);
        Sanctum::actingAs($festivalsOnly, guard: 'devotee');
        $this->getJson('/api/v1/notifications?platform=android')->assertJsonCount(0, 'data');
    }

    public function test_nothing_is_sent_when_nobody_follows_or_the_setting_is_off(): void
    {
        $this->event();
        $this->assertSame(0, app(EventReminders::class)->sendDue());
        $this->assertSame(0, AppNotification::query()->count());

        $this->follower();
        Setting::set(EventReminders::SETTING, '0', 'boolean');
        $this->assertSame(0, app(EventReminders::class)->sendDue());
    }

    public function test_push_goes_to_each_opted_in_followers_devices_not_the_temple_topic(): void
    {
        $wants = $this->follower();
        DevoteeDevice::create(['devotee_id' => $wants->id, 'token' => 'tok-yes', 'token_hash' => DevoteeDevice::hashToken('tok-yes'), 'platform' => 'android']);
        $no = $this->follower(festivals: false);
        DevoteeDevice::create(['devotee_id' => $no->id, 'token' => 'tok-no', 'token_hash' => DevoteeDevice::hashToken('tok-no'), 'platform' => 'android']);
        $this->event();

        $fcm = Mockery::mock(FcmClient::class);
        $fcm->shouldReceive('isConfigured')->andReturn(true);
        $fcm->shouldReceive('send')->once()->withArgs(fn (array $target) => $target === ['token' => 'tok-yes'])->andReturn(true);
        $this->app->instance(FcmClient::class, $fcm);

        $this->assertSame(1, app(EventReminders::class)->sendDue());
        $this->assertSame(1, AppNotification::query()->firstOrFail()->push_count);
    }
}
