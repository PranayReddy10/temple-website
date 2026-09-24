<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\DevoteeResource;
use App\Models\Devotee;
use App\Support\Auth\IdTokenVerifier;
use App\Support\Auth\InvalidIdToken;
use App\Support\Auth\KeysUnavailable;
use App\Support\LoginRecorder;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * "Continue with Google" and "Sign in with Apple".
 *
 * The app signs in with the provider on the device and sends us the identity
 * token; we verify it ourselves and answer with our own API token, exactly as
 * a password sign-in does. An account is found by the provider's stable
 * subject id first; failing that, by a verified email, which links the
 * provider to the account that already has it; failing that, a new account.
 */
class SocialAuthController extends Controller
{
    public function google(Request $request): JsonResponse
    {
        $validated = $request->validate(['id_token' => ['required', 'string', 'max:4096']]);

        $this->ensureEnabled('auth_google_enabled', 'Google');

        $audiences = [
            ...$this->ids(setting('auth_google_client_ids')),
            (string) setting('auth_google_server_client_id'),
            (string) setting('auth_google_ios_client_id'),
        ];

        $claims = $this->verified($request, 'google', fn () => IdTokenVerifier::verify($validated['id_token'], IdTokenVerifier::GOOGLE, $audiences));

        return $this->signIn($request, 'google_id', $claims, $claims['name'] ?? null);
    }

    public function apple(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'identity_token' => ['required', 'string', 'max:4096'],
            // Apple sends the name to the device once, on the very first
            // sign-in, and never puts it in the token. The app passes it on.
            'name' => ['nullable', 'string', 'max:120'],
            'nonce' => ['nullable', 'string', 'max:255'],
        ]);

        $this->ensureEnabled('auth_apple_enabled', 'Apple');

        $claims = $this->verified($request, 'apple', fn () => IdTokenVerifier::verify($validated['identity_token'], IdTokenVerifier::APPLE, $this->ids(setting('auth_apple_client_ids'))));

        // The device hashed a random nonce into its request to Apple; a token
        // replayed from another sign-in carries a different one.
        if (filled($validated['nonce'] ?? null) && ! hash_equals(hash('sha256', $validated['nonce']), (string) ($claims['nonce'] ?? ''))) {
            LoginRecorder::failure('devotee', 'apple', 'bad_nonce', $request);

            throw ValidationException::withMessages(['identity_token' => 'The sign-in could not be confirmed. Please try again.']);
        }

        return $this->signIn($request, 'apple_id', $claims, $validated['name'] ?? null);
    }

    /** @param  array<string, mixed>  $claims */
    protected function signIn(Request $request, string $column, array $claims, ?string $name): JsonResponse
    {
        $subject = (string) $claims['sub'];
        $email = is_string($claims['email'] ?? null) ? Str::lower($claims['email']) : null;
        // Apple sends "true" as a string; Google as a boolean.
        $emailVerified = $email !== null && filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOL);

        [$devotee, $created] = DB::transaction(function () use ($column, $subject, $email, $emailVerified, $name): array {
            $devotee = Devotee::withTrashed()->where($column, $subject)->first();

            if ($devotee === null && $emailVerified) {
                // The provider vouches for this address, so the account that
                // already uses it is the same person's.
                $devotee = Devotee::withTrashed()->where('email', $email)->first();
                $devotee?->forceFill([$column => $subject, 'email_verified_at' => $devotee->email_verified_at ?? now()])->save();
            }

            if ($devotee !== null) {
                return [$devotee, false];
            }

            $devotee = new Devotee([
                'name' => filled($name) ? $name : ($email !== null ? Str::before($email, '@') : 'Devotee'),
                // An unverified address is not taken: it may be someone else's.
                'email' => $emailVerified && ! Devotee::withTrashed()->where('email', $email)->exists() ? $email : null,
            ]);
            $devotee->forceFill([$column => $subject, 'email_verified_at' => $emailVerified ? now() : null])->save();

            return [$devotee, true];
        });

        if ($devotee->trashed() || ! $devotee->is_active) {
            LoginRecorder::failure('devotee', $email ?? $column, 'inactive_account', $request);

            throw ValidationException::withMessages(['identity_token' => 'This account is no longer active.']);
        }

        $devotee->forceFill(['last_seen_at' => now()])->saveQuietly();
        Event::dispatch(new Login('devotee', $devotee, false));

        return response()->json([
            'data' => [
                'devotee' => new DevoteeResource($devotee->fresh()),
                'token' => $devotee->createToken('app')->plainTextToken,
                'created' => $created,
            ],
        ], $created ? 201 : 200);
    }

    /** @return array<string, mixed> */
    protected function verified(Request $request, string $provider, callable $verify): array
    {
        try {
            return $verify();
        } catch (InvalidIdToken $e) {
            LoginRecorder::failure('devotee', $provider, 'invalid_'.$provider.'_token', $request);

            throw ValidationException::withMessages([$provider === 'apple' ? 'identity_token' : 'id_token' => $e->getMessage()]);
        } catch (KeysUnavailable) {
            abort(503, 'Sign-in with '.ucfirst($provider).' is unavailable right now. Please try again.');
        }
    }

    protected function ensureEnabled(string $key, string $label): void
    {
        if (! setting($key, null, false)) {
            abort(403, 'Sign-in with '.$label.' is not available.');
        }
    }

    /** @return array<int, string> */
    protected function ids(mixed $value): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', (string) $value) ?: [])));
    }
}
