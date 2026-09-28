<?php

namespace App\Http\Controllers\Api\V1\Trust\Concerns;

use App\Models\Temple;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * The security boundary of the trust app API.
 *
 * Every temple is looked up through the signed-in user's approved temples,
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

        abort_unless($this->trustUser($request)->administersTemple($id), 404);

        return Temple::query()->findOrFail($id);
    }
}
