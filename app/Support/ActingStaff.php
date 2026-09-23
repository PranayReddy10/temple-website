<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * The staff member behind the current request, if there is one.
 *
 * `Auth::user()` answers a different question: it returns whoever is
 * authenticated on whatever guard is currently the default. Sanctum makes the
 * devotee guard the default for the whole of an API request, so inside one
 * `Auth::user()` is a Devotee — and a Devotee has no role, cannot publish
 * anything, and its id is not a `users.id`.
 *
 * Two things went wrong because of that. A `created_by ??= Auth::id()` wrote
 * a devotee's id into a foreign key pointing at the staff table, silently
 * attributing a record to whichever staff account happened to share that
 * number. And `Auth::user()->canPublish()` threw a BadMethodCallException,
 * turning any temple write made during a signed-in API request into a 500.
 *
 * Asking the staff guard by name is what makes those impossible rather than
 * merely unlikely: this returns a staff User or nothing, never something
 * else that happens to be logged in.
 */
final class ActingStaff
{
    public const GUARD = 'web';

    public static function user(): ?User
    {
        $user = Auth::guard(self::GUARD)->user();

        return $user instanceof User ? $user : null;
    }

    public static function id(): ?int
    {
        return self::user()?->getKey();
    }

    /**
     * Whether anyone at all is signed in, on any guard.
     *
     * The distinction matters to the publish guard: nobody signed in means a
     * seeder, an import or a console command, which are trusted. Somebody
     * signed in who is not staff is not trusted, and must not slip through
     * the same door just because the staff guard returned null.
     */
    public static function someoneIsSignedIn(): bool
    {
        return Auth::check() || Auth::guard(self::GUARD)->check();
    }
}
