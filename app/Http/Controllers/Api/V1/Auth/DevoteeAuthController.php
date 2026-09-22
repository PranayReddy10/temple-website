<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RegisterDevoteeRequest;
use App\Http\Resources\V1\DevoteeResource;
use App\Models\Devotee;
use App\Support\LoginRecorder;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class DevoteeAuthController extends Controller
{
    public function register(RegisterDevoteeRequest $request): JsonResponse
    {
        $devotee = Devotee::create($request->safe()->only([
            'name', 'email', 'phone', 'password', 'locale',
        ]));

        return response()->json([
            'data' => [
                'devotee' => new DevoteeResource($devotee),
                'token' => $devotee->createToken('app')->plainTextToken,
            ],
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // One field for either identifier: the app should not have to ask
            // which kind of account this is before it can offer a login form.
            'identifier' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $devotee = Devotee::query()
            ->where('email', $validated['identifier'])
            ->orWhere('phone', $validated['identifier'])
            ->first();

        // One message for both "no such account" and "wrong password", so the
        // endpoint cannot be used to discover which numbers are registered.
        // The recorded reason is more specific than the response, because the
        // admin looking at a burst of failures needs to tell "one account
        // under attack" from "someone guessing phone numbers".
        if ($devotee === null
            || blank($devotee->password)
            || ! Hash::check($validated['password'], $devotee->password)) {
            LoginRecorder::failure(
                'devotee',
                $validated['identifier'],
                $devotee === null ? 'unknown_account' : 'bad_password',
                $request,
            );

            throw ValidationException::withMessages([
                'identifier' => 'These credentials do not match our records.',
            ]);
        }

        if (! $devotee->is_active) {
            LoginRecorder::failure('devotee', $validated['identifier'], 'inactive_account', $request);

            throw ValidationException::withMessages([
                'identifier' => 'This account is no longer active.',
            ]);
        }

        $devotee->forceFill(['last_seen_at' => now()])->saveQuietly();

        // Raised explicitly: a token-based sign-in never goes through a
        // guard's attempt(), so nothing else would fire it, and the recorder
        // listens for it. One listener covers both panels and the app.
        Event::dispatch(new Login('devotee', $devotee, false));

        return response()->json([
            'data' => [
                'devotee' => new DevoteeResource($devotee),
                'token' => $devotee->createToken('app')->plainTextToken,
            ],
        ]);
    }

    /** Revokes only the token that made this request, not every device. */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['data' => ['message' => 'Signed out.']]);
    }
}
