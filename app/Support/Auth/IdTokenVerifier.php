<?php

namespace App\Support\Auth;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Checks a Google or Apple identity token the app obtained on the device.
 *
 * The signature is checked against the provider's published keys, then the
 * issuer and the audience: a genuine Google token issued to somebody else's
 * app must not sign anyone in here. Keys are cached for six hours and fetched
 * again once if a token names a key we do not have (the provider rotated).
 */
final class IdTokenVerifier
{
    public const GOOGLE = [
        'jwks' => 'https://www.googleapis.com/oauth2/v3/certs',
        'issuers' => ['accounts.google.com', 'https://accounts.google.com'],
    ];

    public const APPLE = [
        'jwks' => 'https://appleid.apple.com/auth/keys',
        'issuers' => ['https://appleid.apple.com'],
    ];

    /**
     * @param  array{jwks: string, issuers: array<int, string>}  $provider
     * @param  array<int, string>  $audiences
     * @return array<string, mixed> the token's claims
     *
     * @throws InvalidIdToken
     */
    public static function verify(string $token, array $provider, array $audiences): array
    {
        $audiences = array_values(array_filter(array_map('trim', $audiences)));

        if ($audiences === []) {
            throw new InvalidIdToken('No client ids are configured for this sign-in method.');
        }

        try {
            $claims = self::decode($token, $provider['jwks'], false);
        } catch (InvalidIdToken|KeysUnavailable $e) {
            throw $e;
        } catch (\UnexpectedValueException $e) {
            // A key id we do not know: the provider may have rotated keys.
            if (! str_contains($e->getMessage(), '"kid"')) {
                throw new InvalidIdToken('The sign-in token is not valid.', previous: $e);
            }
            try {
                $claims = self::decode($token, $provider['jwks'], true);
            } catch (InvalidIdToken|KeysUnavailable $again) {
                throw $again;
            } catch (Throwable $again) {
                throw new InvalidIdToken('The sign-in token is not valid.', previous: $again);
            }
        } catch (Throwable $e) {
            throw new InvalidIdToken('The sign-in token is not valid.', previous: $e);
        }

        if (! in_array($claims['iss'] ?? null, $provider['issuers'], true)) {
            throw new InvalidIdToken('The sign-in token was not issued by the expected provider.');
        }

        $aud = (array) ($claims['aud'] ?? []);

        if (array_intersect($aud, $audiences) === []) {
            throw new InvalidIdToken('The sign-in token was issued to a different app.');
        }

        if (blank($claims['sub'] ?? null)) {
            throw new InvalidIdToken('The sign-in token does not name an account.');
        }

        return $claims;
    }

    /** @return array<string, mixed> */
    private static function decode(string $token, string $jwksUrl, bool $refresh): array
    {
        $cacheKey = 'jwks:'.md5($jwksUrl);

        if ($refresh) {
            Cache::forget($cacheKey);
        }

        $jwks = Cache::remember($cacheKey, now()->addHours(6), function () use ($jwksUrl): array {
            $response = Http::timeout(8)->acceptJson()->get($jwksUrl);

            if (! $response->successful() || ! is_array($response->json('keys'))) {
                throw new KeysUnavailable('Could not fetch the provider\'s signing keys.');
            }

            return $response->json();
        });

        JWT::$leeway = 60;

        try {
            return (array) json_decode(json_encode(JWT::decode($token, JWK::parseKeySet($jwks, 'RS256'))), true);
        } catch (\Firebase\JWT\ExpiredException $e) {
            throw new InvalidIdToken('The sign-in token has expired. Please try again.', previous: $e);
        }
    }
}
