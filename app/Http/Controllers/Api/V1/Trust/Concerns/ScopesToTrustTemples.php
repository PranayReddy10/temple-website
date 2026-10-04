<?php

namespace App\Http\Controllers\Api\V1\Trust\Concerns;

use App\Models\Temple;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * The security boundary of the trust app API.
 *
 * Every temple is looked up through the signed-in user's approved temples
 * (a super admin's reach is every temple),
 * so a temple they do not manage reads as not found — the same answer as a
 * temple that does not exist. Child records (timings, events, sevas) are
 * then looked up through that temple's own relationship, never by a bare id.
 */
trait ScopesToTrustTemples
{
    protected function trustUser(Request $request): User
    {
        /** @var User */
        return $request->user('trust');
    }

    protected function managedTemple(Request $request, int|string $temple): Temple
    {
        $id = (int) $temple;
        $user = $this->trustUser($request);

        // A super admin manages every temple; everyone else only their own.
        abort_unless($user->isSuperAdmin() || $user->administersTemple($id), 404);

        return Temple::query()->findOrFail($id);
    }

    /** Temples whose bookings this account may see and receive; null is all. */
    protected function bookableTempleIds(Request $request): ?array
    {
        $user = $this->trustUser($request);

        return $user->isSuperAdmin() ? null : $user->approvedTempleIds();
    }
}
