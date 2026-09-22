<?php

namespace App\Support;

use App\Models\LoginEvent;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * The one place a sign-in gets written down.
 *
 * Scattering this across the panel listener and the API controller is how the
 * two end up recording different things — one with the IP, one without, one
 * counting failures, one not — and how "sign-ins today" quietly becomes a
 * number that means neither.
 *
 * Recording never breaks a sign-in. An analytics row that cannot be written,
 * because the table is missing during a half-applied deploy or the disk is
 * full, must not stop a devotee getting into the app; the failure is logged
 * and the sign-in proceeds.
 */
final class LoginRecorder
{
    public static function success(
        Authenticatable&Model $account,
        string $guard,
        ?Request $request = null,
    ): ?LoginEvent {
        return self::write([
            'authenticatable_type' => $account->getMorphClass(),
            'authenticatable_id' => $account->getKey(),
            'guard' => $guard,
            'succeeded' => true,
        ], $request);
    }

    /**
     * A failed attempt, with what was typed.
     *
     * The identifier is kept and the password never is — not hashed, not
     * truncated, not at all. A field that holds a password sometimes is a
     * field that holds a password.
     */
    public static function failure(
        string $guard,
        ?string $identifier,
        string $reason,
        ?Request $request = null,
    ): ?LoginEvent {
        return self::write([
            'guard' => $guard,
            'identifier' => $identifier === null ? null : str($identifier)->limit(255)->toString(),
            'succeeded' => false,
            'failure_reason' => $reason,
        ], $request);
    }

    /** @param array<string, mixed> $attributes */
    protected static function write(array $attributes, ?Request $request): ?LoginEvent
    {
        $request ??= request();

        try {
            return LoginEvent::create($attributes + [
                'ip_address' => $request?->ip(),
                'user_agent' => str((string) $request?->userAgent())->limit(1000)->toString() ?: null,
                // Reported by the client rather than sniffed from the user
                // agent, which cannot tell an app from a browser reliably.
                'platform' => self::header($request, 'X-Platform', 32),
                'app_version' => self::header($request, 'X-App-Version', 32),
                'occurred_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    protected static function header(?Request $request, string $name, int $limit): ?string
    {
        $value = $request?->header($name);

        return blank($value) ? null : str($value)->limit($limit, '')->toString();
    }
}
