<?php

namespace App\Support\Auth;

use App\Models\Devotee;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The devotee a Google or Apple sign-in belongs to, for the app's API and
 * the website alike: found by the provider's subject id first; failing
 * that, by a verified email, which links the account; otherwise a new one.
 */
final class SocialAccounts
{
    /**
     * @param  array<string, mixed>  $claims  a verified ID token's claims
     * @return array{0: Devotee, 1: bool} the devotee, and whether it is new
     */
    public static function resolve(string $column, array $claims, ?string $name): array
    {
        $subject = (string) $claims['sub'];
        $email = is_string($claims['email'] ?? null) ? Str::lower($claims['email']) : null;
        // Apple sends "true" as a string; Google as a boolean.
        $emailVerified = $email !== null && filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOL);

        return DB::transaction(function () use ($column, $subject, $email, $emailVerified, $name): array {
            $devotee = Devotee::withTrashed()->where($column, $subject)->first();

            if ($devotee === null && $emailVerified) {
                // The provider vouches for this address. An account that
                // already proved it is the same person's; one that never did
                // may have been opened by someone else with this address, so
                // the provider-verified owner takes it over and whatever
                // password and sign-ins it had stop working.
                $devotee = Devotee::withTrashed()->where('email', $email)->first();
                if ($devotee !== null && $devotee->email_verified_at === null) {
                    $devotee->forceFill(['password' => null])->save();
                    $devotee->tokens()->delete();
                }
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
    }

    /** @return array<int, string> the client ids a Google ID token may be issued to */
    public static function googleAudiences(): array
    {
        return array_values(array_filter([
            ...self::ids(setting('auth_google_client_ids')),
            (string) setting('auth_google_server_client_id'),
            (string) setting('auth_google_ios_client_id'),
        ]));
    }

    /** The Web OAuth client id, which the website's Google button uses. */
    public static function googleWebClientId(): ?string
    {
        $id = trim((string) setting('auth_google_server_client_id'));

        return (bool) setting('auth_google_enabled', null, false) && $id !== '' ? $id : null;
    }

    /** @return array<int, string> */
    public static function ids(mixed $value): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', (string) $value) ?: [])));
    }
}
