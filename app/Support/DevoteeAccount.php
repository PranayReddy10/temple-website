<?php

namespace App\Support;

use App\Models\Devotee;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Deleting a devotee's account, as the Privacy policy and the Account
 * deletion page describe it.
 *
 * Their own content goes: profile, check-ins, photos, memories, reviews,
 * yatras, saved and followed temples, devices and sign-ins. Bookings and
 * payments stay, as accounting law requires, attached to a nameless,
 * soft-deleted record so totals and the temples' booking lists still add
 * up; a future booking can still be performed.
 */
final class DevoteeAccount
{
    public static function delete(Devotee $devotee): void
    {
        $files = [];

        DB::transaction(function () use ($devotee, &$files): void {
            foreach ($devotee->photos()->get() as $photo) {
                $files[] = [$photo->disk, $photo->original_path];
                $files[] = [$photo->disk, $photo->stamp_path];
                $photo->delete();
            }
            if ($devotee->avatar_path) {
                $files[] = [$devotee->avatar_disk, $devotee->avatar_path];
            }

            $devotee->memories()->delete();
            $devotee->visits()->delete();
            $devotee->yatras()->get()->each->delete();
            $devotee->reviews()->get()->each->delete();
            $devotee->likes()->delete();
            $devotee->follows()->delete();
            $devotee->savedTemples()->detach();
            $devotee->devices()->delete();
            $devotee->tokens()->delete();
            DB::table('devotee_password_resets')->where('devotee_id', $devotee->getKey())->delete();

            $devotee->forceFill([
                'name' => 'Deleted account',
                'email' => null,
                'phone' => null,
                'password' => null,
                'avatar_path' => null,
                'avatar_disk' => null,
                'home_state_id' => null,
                'date_of_birth' => null,
                'gender' => null,
                'google_id' => null,
                'apple_id' => null,
                'passport_code' => Str::random(32),
                'is_active' => false,
            ])->save();

            $devotee->delete();
        });

        // After the commit, and best effort: a missing file is no reason to
        // keep an account its owner asked us to delete.
        foreach ($files as [$disk, $path]) {
            if (blank($path)) {
                continue;
            }
            try {
                Storage::disk($disk ?: config('filesystems.default'))->delete($path);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
