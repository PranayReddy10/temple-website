<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\PublicPassportResource;
use App\Models\Devotee;
use App\Support\PassportQr;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A devotee's passport code: showing it, resetting it, and reading the
 * passport behind someone else's.
 */
class PassportShareController extends Controller
{
    /** The signed-in devotee's own code. */
    public function mine(Request $request): JsonResponse
    {
        return $this->code($request->user());
    }

    /**
     * A new code, for when the old one was shown to someone who should not
     * have it. Every copy already scanned, printed or screenshotted stops
     * opening the passport.
     */
    public function reset(Request $request): JsonResponse
    {
        $request->user()->resetPassportCode();

        return $this->code($request->user());
    }

    /**
     * Someone else's passport, from the code they showed.
     *
     * Open to anyone holding the code: showing it is the holder's consent,
     * and a devotee at the next temple over should not need an account to
     * see a friend's stamps. The code is a random token, so there is nothing
     * to enumerate, and an unknown one is a plain 404.
     */
    public function show(string $code): PublicPassportResource
    {
        $devotee = Devotee::findByPassportCode($code);

        if ($devotee === null) {
            throw new NotFoundHttpException('This passport code is not recognised. It may have been reset.');
        }

        return new PublicPassportResource($devotee->load('homeState:id,name'));
    }

    protected function code(Devotee $devotee): JsonResponse
    {
        return response()->json(['data' => [
            'code' => $devotee->passportCode(),
            'url' => PassportQr::url($devotee),
        ]]);
    }
}
