<?php

namespace App\Support\Push;

use App\Models\Setting;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Firebase Cloud Messaging, HTTP v1.
 *
 * Authenticates as the service account saved in the admin panel: a JWT
 * signed with its private key is exchanged for an access token, cached until
 * shortly before it expires. No Firebase SDK is needed on the server.
 */
class FcmClient
{
    /** @return array<string, mixed> */
    protected function serviceAccount(): array
    {
        $json = Setting::secret('firebase_service_account');
        $account = $json === null ? null : json_decode($json, true);

        if (! is_array($account) || blank($account['client_email'] ?? null) || blank($account['private_key'] ?? null)) {
            throw new RuntimeException('The Firebase service account is missing or not valid JSON.');
        }

        return $account;
    }

    public function isConfigured(): bool
    {
        try {
            $this->serviceAccount();

            return filled($this->projectId()) && (bool) setting('push_enabled', null, false);
        } catch (RuntimeException) {
            return false;
        }
    }

    protected function projectId(): ?string
    {
        return setting('firebase_project_id') ?: ($this->serviceAccountOrNull()['project_id'] ?? null);
    }

    /** @return array<string, mixed>|null */
    protected function serviceAccountOrNull(): ?array
    {
        try {
            return $this->serviceAccount();
        } catch (RuntimeException) {
            return null;
        }
    }

    protected function accessToken(): string
    {
        $account = $this->serviceAccount();

        return Cache::remember('fcm:token:'.md5($account['client_email']), now()->addMinutes(50), function () use ($account): string {
            $now = time();
            $assertion = JWT::encode([
                'iss' => $account['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600,
            ], $account['private_key'], 'RS256');

            $response = Http::asForm()->timeout(10)->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ]);

            if (! $response->successful() || blank($response->json('access_token'))) {
                throw new RuntimeException('Google refused the service account: '.($response->json('error_description') ?? $response->status()));
            }

            return $response->json('access_token');
        });
    }

    /**
     * Sends one message. $target is ['topic' => …], ['condition' => …] or
     * ['token' => …]. Returns false for a token Firebase says is gone, so
     * the caller can forget that device.
     *
     * @param  array<string, string>  $target
     * @param  array<string, string>  $data
     */
    public function send(array $target, string $title, string $body, ?string $image = null, array $data = []): bool
    {
        $notification = array_filter(['title' => $title, 'body' => $body, 'image' => $image]);

        $response = Http::withToken($this->accessToken())
            ->timeout(10)
            ->post('https://fcm.googleapis.com/v1/projects/'.$this->projectId().'/messages:send', [
                'message' => $target + [
                    'notification' => $notification,
                    'data' => $data,
                    'android' => ['priority' => 'high'],
                    'apns' => ['payload' => ['aps' => ['sound' => 'default']]],
                ],
            ]);

        if ($response->successful()) {
            return true;
        }

        $status = $response->json('error.status');

        if (isset($target['token']) && in_array($status, ['NOT_FOUND', 'UNREGISTERED', 'INVALID_ARGUMENT'], true)) {
            return false;
        }

        throw new RuntimeException('Firebase refused the message: '.($response->json('error.message') ?? $response->status()));
    }
}
