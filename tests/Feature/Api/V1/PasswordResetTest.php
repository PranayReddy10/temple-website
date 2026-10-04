<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Filament\Pages\Settings\ManageEmail;
use App\Filament\Pages\Settings\ManageSignIn;
use App\Filament\Resources\Devotees\Pages\ViewDevotee;
use App\Mail\DevoteePasswordResetCode;
use App\Models\Devotee;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private function sentCode(): string
    {
        $code = null;
        Mail::assertSent(DevoteePasswordResetCode::class, function (DevoteePasswordResetCode $mail) use (&$code): bool {
            $code = $mail->code;

            return true;
        });

        return $code;
    }

    public function test_a_code_is_emailed_and_resets_the_password(): void
    {
        Mail::fake();
        $devotee = Devotee::factory()->create(['email' => 'reset@example.com', 'password' => 'old-password']);
        $old = $devotee->createToken('app')->plainTextToken;

        $this->postJson('/api/v1/auth/password/forgot', ['email' => 'Reset@Example.com'])->assertOk();
        $code = $this->sentCode();
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);

        $response = $this->postJson('/api/v1/auth/password/reset', [
            'email' => 'reset@example.com',
            'code' => $code,
            'password' => 'new-password-1',
            'password_confirmation' => 'new-password-1',
        ])->assertOk();

        $this->assertNotEmpty($response->json('data.token'));
        $this->assertTrue(Hash::check('new-password-1', $devotee->fresh()->password));
        $this->assertSame(1, $devotee->tokens()->count(), 'old devices are signed out');
        $this->assertNotSame($old, $response->json('data.token'));

        // Used once only.
        $this->postJson('/api/v1/auth/password/reset', [
            'email' => 'reset@example.com', 'code' => $code,
            'password' => 'another-pass', 'password_confirmation' => 'another-pass',
        ])->assertStatus(422);
    }

    public function test_unknown_email_gets_the_same_answer_and_no_mail(): void
    {
        Mail::fake();

        $this->postJson('/api/v1/auth/password/forgot', ['email' => 'nobody@example.com'])->assertOk();

        Mail::assertNothingSent();
    }

    public function test_five_wrong_codes_kill_the_code(): void
    {
        Mail::fake();
        Devotee::factory()->create(['email' => 'guess@example.com']);
        $this->postJson('/api/v1/auth/password/forgot', ['email' => 'guess@example.com'])->assertOk();
        $code = $this->sentCode();
        $wrong = $code === '000000' ? '111111' : '000000';

        foreach (range(1, 5) as $_) {
            $this->postJson('/api/v1/auth/password/reset', [
                'email' => 'guess@example.com', 'code' => $wrong,
                'password' => 'new-password-1', 'password_confirmation' => 'new-password-1',
            ])->assertStatus(422);
        }

        $this->postJson('/api/v1/auth/password/reset', [
            'email' => 'guess@example.com', 'code' => $code,
            'password' => 'new-password-1', 'password_confirmation' => 'new-password-1',
        ])->assertStatus(422);
    }

    public function test_it_can_be_switched_off_in_the_admin_panel(): void
    {
        Setting::set('password_reset_enabled', '0', 'boolean');

        $this->postJson('/api/v1/auth/password/forgot', ['email' => 'a@example.com'])->assertForbidden();
        $this->getJson('/api/v1/app/config')->assertJsonPath('data.auth.password_reset', false);
    }

    public function test_sign_in_ignores_email_case(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Case', 'email' => 'Case@Example.com',
            'password' => 'a-good-password', 'password_confirmation' => 'a-good-password',
        ])->assertCreated();

        $this->postJson('/api/v1/auth/login', ['identifier' => 'case@example.COM', 'password' => 'a-good-password'])->assertOk();
    }

    public function test_browsing_does_not_use_up_the_sign_in_limit(): void
    {
        Devotee::factory()->create(['email' => 'busy@example.com', 'password' => 'a-good-password']);

        // Plenty of ordinary app traffic first, more than sign-in's own limit.
        foreach (range(1, 20) as $_) {
            $this->getJson('/api/v1/app/config')->assertOk();
        }

        // Register counts separately from login, too.
        foreach (range(1, 3) as $i) {
            $this->postJson('/api/v1/auth/register', [
                'name' => 'N', 'email' => "n{$i}@example.com",
                'password' => 'a-good-password', 'password_confirmation' => 'a-good-password',
            ])->assertCreated();
        }

        foreach (range(1, 10) as $_) {
            $this->postJson('/api/v1/auth/login', ['identifier' => 'busy@example.com', 'password' => 'a-good-password'])->assertOk();
        }

        $this->postJson('/api/v1/auth/login', ['identifier' => 'busy@example.com', 'password' => 'a-good-password'])->assertStatus(429);
    }

    public function test_admin_email_page_saves_smtp_and_applies_it(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin, 'is_active' => true]));

        Livewire::test(ManageEmail::class)
            ->set('data.mail_host', 'smtp.hostinger.com')
            ->set('data.mail_port', 465)
            ->set('data.mail_encryption', 'ssl')
            ->set('data.mail_username', 'no-reply@example.com')
            ->set('data.mail_password', 'secret-pass')
            ->set('data.mail_from_address', 'no-reply@example.com')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('secret-pass', Setting::secret('mail_password'));
        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('smtp.hostinger.com', config('mail.mailers.smtp.host'));
        $this->assertSame('smtps', config('mail.mailers.smtp.scheme'));
        $this->assertSame('no-reply@example.com', config('mail.from.address'));
    }

    public function test_a_provider_fills_in_its_server_and_ses_goes_through_the_api(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin, 'is_active' => true]));

        Livewire::test(ManageEmail::class)
            ->set('data.mail_provider', 'sendgrid')
            ->assertSet('data.mail_host', 'smtp.sendgrid.net')
            ->assertSet('data.mail_port', 587)
            ->assertSet('data.mail_encryption', 'tls')
            ->assertSee('apikey')
            ->set('data.mail_username', 'apikey')
            ->set('data.mail_password', 'SG.key')
            ->call('save')
            ->assertHasNoErrors();
        $this->assertSame('smtp.sendgrid.net', config('mail.mailers.smtp.host'));
        $this->assertSame('smtp', config('mail.mailers.smtp.scheme'));

        Livewire::test(ManageEmail::class)
            ->set('data.mail_provider', 'ses')
            ->set('data.mail_ses_key', 'AKIAEXAMPLE')
            ->set('data.mail_ses_secret', 'ses-secret')
            ->set('data.mail_ses_region', 'ap-south-1')
            ->call('save')
            ->assertHasNoErrors();
        $this->assertSame('ses', config('mail.default'));
        $this->assertSame('AKIAEXAMPLE', config('services.ses.key'));
        $this->assertSame('ses-secret', config('services.ses.secret'));
        $this->assertSame('ap-south-1', config('services.ses.region'));
        $this->assertTrue(\App\Support\MailSettings::configured());

        Livewire::test(ManageEmail::class)->set('data.mail_provider', 'log')->call('save');
        $this->assertFalse(\App\Support\MailSettings::configured());
    }

    public function test_staff_can_reset_a_forgotten_password_by_email(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        $user = User::factory()->create(['role' => UserRole::Editor, 'is_active' => true, 'email' => 'editor@example.com']);

        $this->get('/admin/login')->assertOk()->assertSee('/admin/password-reset/request', false);
        $this->get('/temple/login')->assertOk()->assertSee('/temple/password-reset/request', false);

        // Asked from the temple panel, an admin editor gets nothing: a link
        // only ever opens the panel its account belongs to.
        \Filament\Facades\Filament::setCurrentPanel('temple');
        Livewire::test(\Filament\Auth\Pages\PasswordReset\RequestPasswordReset::class)
            ->fillForm(['email' => 'editor@example.com'])
            ->call('request');
        \Illuminate\Support\Facades\Notification::assertNothingSent();

        \Illuminate\Support\Facades\DB::table('password_reset_tokens')->delete();
        \Filament\Facades\Filament::setCurrentPanel('admin');
        Livewire::test(\Filament\Auth\Pages\PasswordReset\RequestPasswordReset::class)
            ->fillForm(['email' => 'editor@example.com'])
            ->call('request');

        \Illuminate\Support\Facades\Notification::assertSentTo($user, \Filament\Auth\Notifications\ResetPassword::class);
    }

    public function test_google_needs_its_client_id_before_it_can_be_turned_on(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin, 'is_active' => true]));

        Livewire::test(ManageSignIn::class)
            ->set('data.auth_google_enabled', true)
            ->set('data.auth_google_server_client_id', null)
            ->call('save')
            ->assertHasErrors(['data.auth_google_server_client_id']);
    }

    public function test_admin_can_send_a_code_or_set_a_password(): void
    {
        Mail::fake();
        $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin, 'is_active' => true]));
        $devotee = Devotee::factory()->create(['email' => 'help@example.com']);

        Livewire::test(ViewDevotee::class, ['record' => $devotee->getRouteKey()])
            ->callAction('send_reset_code')
            ->assertHasNoActionErrors()
            ->callAction('set_password', ['password' => 'admin-set-pass'])
            ->assertHasNoActionErrors();

        Mail::assertSent(DevoteePasswordResetCode::class);
        $this->assertTrue(Hash::check('admin-set-pass', $devotee->fresh()->password));
    }
}
