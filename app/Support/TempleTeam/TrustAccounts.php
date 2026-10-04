<?php

namespace App\Support\TempleTeam;

use App\Models\TempleUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** A temple team member leaving: their account is erased, the temples' records stay. */
final class TrustAccounts
{
    public static function delete(User $user): void
    {
        DB::transaction(function () use ($user): void {
            // Signed out of every device, and out of the portal.
            $user->tokens()->delete();
            DB::table('sessions')->where('user_id', $user->getKey())->delete();

            // No access to any temple, and no claim left waiting.
            TempleUser::query()->where('user_id', $user->getKey())->delete();

            // The row stays (temples' records name who changed what), but
            // nothing in it identifies the person or lets anyone sign in.
            $user->forceFill([
                'name' => 'Deleted account',
                'email' => 'deleted-'.$user->getKey().'-'.Str::lower(Str::random(8)).'@deleted.invalid',
                'phone' => null,
                'password' => Str::password(40),
                'is_active' => false,
                'remember_token' => null,
            ])->save();
        });
    }
}
