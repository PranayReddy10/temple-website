<?php

namespace App\Support;

use App\Mail\DevoteePasswordResetCode;
use App\Models\Devotee;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

/**
 * "Forgot password": a six-digit code sent by email, typed into the app.
 *
 * A code rather than a link, because the app has no web page to land on and a
 * code works on whichever device reads the mail. It is stored hashed, lives
 * for a few minutes (Admin → App → Sign-in methods), and dies after five wrong
 * guesses, so the million possible codes cannot be walked.
 */
final class DevoteePasswordReset
{
    public const MAX_ATTEMPTS = 5;

    public static function enabled(): bool
    {
        return (bool) setting('password_reset_enabled', null, true)
            && (bool) setting('auth_password_enabled', null, true);
    }

    public static function minutes(): int
    {
        return max(5, min(120, (int) setting('password_reset_minutes', null, 15)));
    }

    public static function findByEmail(?string $email): ?Devotee
    {
        $email = mb_strtolower(trim((string) $email));

        return $email === '' ? null : Devotee::query()->whereRaw('LOWER(email) = ?', [$email])->first();
    }

    /** Issues a fresh code, replacing any earlier one, and mails it. */
    public static function send(Devotee $devotee): void
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        DB::table('devotee_password_resets')->updateOrInsert(
            ['devotee_id' => $devotee->id],
            [
                'code_hash' => Hash::make($code),
                'attempts' => 0,
                'expires_at' => now()->addMinutes(self::minutes()),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        Mail::to($devotee->email)->send(new DevoteePasswordResetCode($devotee, $code, self::minutes()));
    }

    /**
     * Whether the code is right. A wrong guess counts; the fifth kills the
     * code, and so does using it.
     */
    public static function check(Devotee $devotee, string $code): bool
    {
        $row = DB::table('devotee_password_resets')->where('devotee_id', $devotee->id)->first();

        if ($row === null || now()->greaterThan($row->expires_at) || $row->attempts >= self::MAX_ATTEMPTS) {
            return false;
        }

        if (! Hash::check($code, $row->code_hash)) {
            DB::table('devotee_password_resets')->where('id', $row->id)->increment('attempts');

            return false;
        }

        DB::table('devotee_password_resets')->where('id', $row->id)->delete();

        return true;
    }

    /** Sets the password and signs every device out. */
    public static function setPassword(Devotee $devotee, string $password): void
    {
        $devotee->forceFill(['password' => $password])->save();
        $devotee->tokens()->delete();
        DB::table('devotee_password_resets')->where('devotee_id', $devotee->id)->delete();
    }
}
