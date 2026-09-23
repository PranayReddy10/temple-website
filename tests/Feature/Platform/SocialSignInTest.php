<?php

namespace Tests\Feature\Platform;

use App\Models\Devotee;
use App\Models\Setting;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Google and Apple sign-in. Tokens here are signed with a key made for the
 * test and published through a faked JWKS endpoint, so the real verification
 * path runs end to end.
 */
class SocialSignInTest extends TestCase
{
    use RefreshDatabase;

    private string $private = '';

    /** @var array<string, mixed> */
    private array $jwk;

    protected function setUp(): void
    {
        parent::setUp();

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $this->private);
        $rsa = openssl_pkey_get_details($key)['rsa'];
        $b64 = fn (string $v) => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
        $this->jwk = ['kty' => 'RSA', 'kid' => 'test-key', 'use' => 'sig', 'alg' => 'RS256', 'n' => $b64($rsa['n']), 'e' => $b64($rsa['e'])];

        Http::fake([
            'www.googleapis.com/*' => Http::response(['keys' => [$this->jwk]]),
            'appleid.apple.com/*' => Http::response(['keys' => [$this->jwk]]),
        ]);
        Cache::flush();

        Setting::set('auth_google_enabled', '1', 'boolean');
        Setting::set('auth_google_server_client_id', 'web-client.apps.googleusercontent.com');
        Setting::set('auth_apple_enabled', '1', 'boolean');
        Setting::set('auth_apple_client_ids', 'com.example.temple');
    }

    /** @param  array<string, mixed>  $claims */
    private function token(array $claims): string
    {
        return JWT::encode($claims + ['iat' => time(), 'exp' => time() + 600], $this->private, 'RS256', 'test-key');
    }

    private function google(array $claims = []): string
    {
        return $this->token($claims + [
            'iss' => 'https://accounts.google.com', 'aud' => 'web-client.apps.googleusercontent.com',
            'sub' => 'g-123', 'email' => 'Meera@Example.com', 'email_verified' => true, 'name' => 'Meera Devi',
        ]);
    }

    public function test_a_new_google_account_is_created_and_signed_in(): void
    {
        $response = $this->postJson('/api/v1/auth/google', ['id_token' => $this->google()])->assertCreated();

        $response->assertJsonPath('data.created', true)
            ->assertJsonPath('data.devotee.name', 'Meera Devi')
            ->assertJsonPath('data.devotee.email', 'meera@example.com')
            ->assertJsonPath('data.devotee.sign_in_methods', ['google']);
        $this->assertNotEmpty($response->json('data.token'));

        // Again: the same account, not a second one.
        $this->postJson('/api/v1/auth/google', ['id_token' => $this->google()])->assertOk()->assertJsonPath('data.created', false);
        $this->assertSame(1, Devotee::count());
    }

    public function test_a_verified_google_email_links_the_existing_account(): void
    {
        $existing = Devotee::factory()->create(['email' => 'meera@example.com']);

        $this->postJson('/api/v1/auth/google', ['id_token' => $this->google()])->assertOk();

        $this->assertSame('g-123', $existing->fresh()->google_id);
        $this->assertSame(1, Devotee::count());
    }

    public function test_an_unverified_email_is_neither_linked_nor_taken(): void
    {
        Devotee::factory()->create(['email' => 'meera@example.com']);

        $this->postJson('/api/v1/auth/google', ['id_token' => $this->google(['email_verified' => false])])->assertCreated();

        $this->assertSame(2, Devotee::count());
        $this->assertNull(Devotee::query()->where('google_id', 'g-123')->value('email'));
    }

    public function test_a_token_for_another_app_or_badly_signed_is_refused(): void
    {
        $this->postJson('/api/v1/auth/google', ['id_token' => $this->google(['aud' => 'someone-else'])])
            ->assertUnprocessable()->assertJsonValidationErrors('id_token');

        $other = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($other, $otherPem);
        $forged = JWT::encode(['iss' => 'https://accounts.google.com', 'aud' => 'web-client.apps.googleusercontent.com', 'sub' => 'x', 'exp' => time() + 60], $otherPem, 'RS256', 'test-key');

        $this->postJson('/api/v1/auth/google', ['id_token' => $forged])->assertUnprocessable();
        $this->assertSame(0, Devotee::count());
    }

    public function test_a_disabled_method_is_refused(): void
    {
        Setting::set('auth_google_enabled', '0', 'boolean');

        $this->postJson('/api/v1/auth/google', ['id_token' => $this->google()])->assertForbidden();
    }

    public function test_apple_sign_in_checks_the_nonce_and_takes_the_name_from_the_app(): void
    {
        $claims = ['iss' => 'https://appleid.apple.com', 'aud' => 'com.example.temple', 'sub' => 'a-1', 'email' => 'x@privaterelay.appleid.com', 'email_verified' => 'true', 'nonce' => hash('sha256', 'raw-nonce')];

        $this->postJson('/api/v1/auth/apple', ['identity_token' => $this->token($claims), 'nonce' => 'wrong'])->assertUnprocessable();

        $this->postJson('/api/v1/auth/apple', ['identity_token' => $this->token($claims), 'nonce' => 'raw-nonce', 'name' => 'Arjun Rao'])
            ->assertCreated()
            ->assertJsonPath('data.devotee.name', 'Arjun Rao')
            ->assertJsonPath('data.devotee.sign_in_methods', ['apple']);
    }

    public function test_password_sign_up_can_be_switched_off(): void
    {
        Setting::set('auth_password_enabled', '0', 'boolean');

        $this->postJson('/api/v1/auth/register', ['name' => 'A', 'email' => 'a@example.com', 'password' => 'secret-pass-123', 'password_confirmation' => 'secret-pass-123'])
            ->assertForbidden();

        $this->getJson('/api/v1/app/config')->assertJsonPath('data.auth.password', false)->assertJsonPath('data.auth.google.enabled', true);
    }
}
