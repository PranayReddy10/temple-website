<?php

namespace Tests\Feature\Platform;

use App\Enums\TempleStatus;
use App\Enums\UserRole;
use App\Filament\Pages\Settings\ManageAds;
use App\Filament\Pages\Settings\ManageAppControl;
use App\Filament\Pages\Settings\ManagePayments;
use App\Filament\Pages\Settings\ManagePush;
use App\Filament\Pages\Settings\ManageSignIn;
use App\Filament\Resources\AppNotifications\Pages\ManageAppNotifications;
use App\Filament\Resources\DevoteeSubscriptions\Pages\ManageDevoteeSubscriptions;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Filament\Resources\SubscriptionPlans\Pages\ManageSubscriptionPlans;
use App\Models\AppNotification;
use App\Models\Devotee;
use App\Models\DevoteeDevice;
use App\Models\Setting;
use App\Models\SubscriptionPlan;
use App\Models\Temple;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/** Maintenance, updates, ads and notifications, as set in the admin panel. */
class AppControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_maintenance_and_update_rules_reach_the_app(): void
    {
        Setting::set('app_maintenance_enabled', '1', 'boolean');
        Setting::set('app_maintenance_message', 'Back at 6 pm.');
        Setting::set('app_android_latest_version', '0.7.0');
        Setting::set('app_android_min_version', '0.6');

        $this->getJson('/api/v1/app/config?platform=android&version=0.5.0')->assertOk()
            ->assertJsonPath('data.maintenance.enabled', true)
            ->assertJsonPath('data.maintenance.message', 'Back at 6 pm.')
            ->assertJsonPath('data.update.required', true);

        $this->getJson('/api/v1/app/config?platform=android&version=0.6.5')
            ->assertJsonPath('data.update.required', false)
            ->assertJsonPath('data.update.available', true);

        $this->getJson('/api/v1/app/config?platform=android&version=0.7.0')->assertJsonPath('data.update.available', false);
        // Rules are per platform.
        $this->getJson('/api/v1/app/config?platform=ios&version=0.1.0')->assertJsonPath('data.update.required', false);
    }

    public function test_ads_follow_the_settings_and_use_test_units_until_switched(): void
    {
        $this->getJson('/api/v1/app/config?platform=android')->assertJsonPath('data.ads.enabled', false);

        Setting::set('ads_enabled', '1', 'boolean');
        Setting::set('ads_admob_android_native', 'ca-app-pub-1/real');

        $this->getJson('/api/v1/app/config?platform=android')
            ->assertJsonPath('data.ads.enabled', true)
            ->assertJsonPath('data.ads.network', 'admob')
            ->assertJsonPath('data.ads.units.native', 'ca-app-pub-3940256099942544/2247696110');

        Setting::set('ads_test_mode', '0', 'boolean');
        $this->getJson('/api/v1/app/config?platform=android')->assertJsonPath('data.ads.units.native', 'ca-app-pub-1/real');
        $this->getJson('/api/v1/app/config?platform=web')->assertJsonPath('data.ads.enabled', false);
    }

    public function test_the_inbox_holds_what_is_addressed_to_the_reader(): void
    {
        $temple = Temple::create(['name' => 'Followed Temple', 'status' => TempleStatus::Published]);
        $devotee = Devotee::factory()->create(['created_at' => now()->subYear()]);
        // Following is what asks to be told about a temple; saving is a bookmark.
        $devotee->follows()->create(['temple_id' => $temple->id]);
        $other = Devotee::factory()->create();

        $send = fn (array $a) => AppNotification::create($a + ['title' => $a['title'], 'body' => 'b'])->forceFill(['status' => 'sent', 'sent_at' => now()])->save();
        $send(['title' => 'Everyone']);
        $send(['title' => 'iPhones', 'audience' => 'platform', 'platform' => 'ios']);
        $send(['title' => 'Followers', 'audience' => 'temple', 'audience_id' => $temple->id]);
        $send(['title' => 'Just you', 'audience' => 'devotee', 'audience_id' => $devotee->id]);
        $send(['title' => 'Someone else', 'audience' => 'devotee', 'audience_id' => $other->id]);
        AppNotification::create(['title' => 'Draft', 'body' => 'b']);

        $this->getJson('/api/v1/notifications?platform=android')->assertJsonCount(1, 'data');

        Sanctum::actingAs($devotee, guard: 'devotee');
        $titles = collect($this->getJson('/api/v1/notifications?platform=android')->json('data'))->pluck('title')->sort()->values()->all();
        $this->assertSame(['Everyone', 'Followers', 'Just you'], $titles);

        $id = AppNotification::query()->where('title', 'Everyone')->value('id');
        $this->postJson("/api/v1/me/notifications/{$id}/read")->assertOk();
        $this->postJson('/api/v1/me/notifications/'.AppNotification::query()->where('title', 'Someone else')->value('id').'/read')->assertNotFound();
        $this->postJson('/api/v1/me/notifications/read-all', ['platform' => 'android'])->assertJsonPath('data.marked', 2);
    }

    public function test_devices_register_and_a_send_pushes_through_firebase(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($key, $pem);
        Setting::set('push_enabled', '1', 'boolean');
        Setting::set('firebase_project_id', 'temple-app');
        Setting::set('firebase_service_account', json_encode(['client_email' => 'svc@temple-app.iam.gserviceaccount.com', 'private_key' => $pem, 'project_id' => 'temple-app']), 'secret');
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.test']),
            'fcm.googleapis.com/*' => Http::response(['name' => 'projects/temple-app/messages/1']),
        ]);

        $devotee = Devotee::factory()->create();
        Sanctum::actingAs($devotee, guard: 'devotee');
        $this->postJson('/api/v1/devices', ['token' => 'fcm-token-1', 'platform' => 'android', 'app_version' => '0.6.0'])->assertCreated();
        $this->postJson('/api/v1/devices', ['token' => 'fcm-token-1', 'platform' => 'android'])->assertOk();
        $this->assertSame($devotee->id, DevoteeDevice::query()->sole()->devotee_id);

        $n = AppNotification::create(['title' => 'Karthika Deepam', 'body' => 'Lamps tonight', 'scheduled_at' => now()->subMinute(), 'status' => 'scheduled']);
        $this->artisan('notifications:send-due')->assertSuccessful();

        $this->assertSame('sent', $n->fresh()->status);
        $this->assertNull($n->fresh()->last_error);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'messages:send') && $r['message']['topic'] === 'all' && $r['message']['notification']['title'] === 'Karthika Deepam');
    }

    public function test_secrets_are_encrypted_and_a_blank_field_keeps_them(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin, 'is_active' => true]));

        Livewire::test(ManagePayments::class)
            ->set('data.payments_razorpay_key_id', 'rzp_live_1')
            ->set('data.payments_razorpay_key_secret', 'top-secret')
            ->call('save');

        $this->assertSame('top-secret', Setting::secret('payments_razorpay_key_secret'));
        $this->assertStringNotContainsString('top-secret', (string) Setting::query()->where('key', 'payments_razorpay_key_secret')->value('value'));

        Livewire::test(ManagePayments::class)
            ->assertSet('data.payments_razorpay_key_secret', null)
            ->assertDontSee('top-secret')
            ->call('save');
        $this->assertSame('top-secret', Setting::secret('payments_razorpay_key_secret'));
    }

    public function test_every_new_admin_screen_renders_for_a_super_admin_and_not_for_an_editor(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin, 'is_active' => true]);
        $this->actingAs($admin);

        foreach ([ManageAppControl::class, ManageSignIn::class, ManagePush::class, ManageAds::class, ManagePayments::class,
            ManageAppNotifications::class, ManageSubscriptionPlans::class, ListPayments::class, ManageDevoteeSubscriptions::class] as $page) {
            Livewire::test($page)->assertOk();
        }

        $this->actingAs(User::factory()->create(['role' => UserRole::Editor, 'is_active' => true]));
        $this->get('/admin/monetisation/payments')->assertForbidden();
        $this->get('/admin/app/notifications')->assertOk();
    }

    public function test_admin_creates_a_plan_in_rupees_and_grants_it(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin, 'is_active' => true]));
        $devotee = Devotee::factory()->create();

        Livewire::test(ManageSubscriptionPlans::class)
            ->callAction('create', ['name' => 'Yatri Plus', 'code' => 'yatri-plus', 'price_rupees' => '49.50', 'duration_days' => 30, 'benefits' => ['no_ads' => true, 'memory_photos_per_visit' => 10], 'is_active' => true])
            ->assertHasNoActionErrors();

        $plan = SubscriptionPlan::query()->sole();
        $this->assertSame(4950, $plan->price_paise);
        $this->assertSame(['no_ads' => true, 'memory_photos_per_visit' => 10], $plan->benefits);

        Livewire::test(ManageDevoteeSubscriptions::class)
            ->callAction('create', ['devotee_id' => $devotee->id, 'subscription_plan_id' => $plan->id, 'days' => 7, 'note' => 'Temple partner'])
            ->assertHasNoActionErrors();

        $this->assertTrue($devotee->entitlements()['no_ads']);
    }

    public function test_admin_composes_and_sends_a_notification(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin, 'is_active' => true]));

        Livewire::test(ManageAppNotifications::class)
            ->callAction('create', ['title' => 'Maha Shivaratri', 'body' => 'Night-long darshan at every Shiva temple.', 'link_type' => 'none', 'audience' => 'all'])
            ->assertHasNoActionErrors();

        $n = AppNotification::query()->sole();
        $this->assertSame('draft', $n->status);

        Livewire::test(ManageAppNotifications::class)->callTableAction('send', $n);

        $this->assertSame('sent', $n->fresh()->status);
    }
}
