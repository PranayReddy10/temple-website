<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\DevoteeResource;
use App\Support\DevoteePasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Forgot password, for the app: ask for a code, then trade it for a new
 * password. Switched on and off in Admin → App → Sign-in methods.
 */
class PasswordResetController extends Controller
{
    public function forgot(Request $request): JsonResponse
    {
        abort_unless(DevoteePasswordReset::enabled(), 403, 'Password reset is not available. Please contact support.');

        $validated = $request->validate(['email' => ['required', 'email', 'max:255']]);

        $devotee = DevoteePasswordReset::findByEmail($validated['email']);

        // The same answer whether or not the account exists, so this cannot
        // be used to find out who has one.
        if ($devotee !== null && $devotee->is_active) {
            try {
                DevoteePasswordReset::send($devotee);
            } catch (Throwable $e) {
                // Mail not set up, or the server refused it. Worth an error in
                // the log, and an honest "try later" to the person waiting.
                Log::error('Password reset email failed', ['devotee' => $devotee->id, 'error' => $e->getMessage()]);
                abort(503, 'We could not send the email right now. Please try again later.');
            }
        }

        return response()->json(['data' => [
            'message' => 'If an account uses that email, a code is on its way.',
            'expires_in_minutes' => DevoteePasswordReset::minutes(),
        ]]);
    }

    public function reset(Request $request): JsonResponse
    {
        abort_unless(DevoteePasswordReset::enabled(), 403, 'Password reset is not available. Please contact support.');

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'code' => ['required', 'string', 'size:6'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
        ]);

        $devotee = DevoteePasswordReset::findByEmail($validated['email']);

        if ($devotee === null || ! $devotee->is_active || ! DevoteePasswordReset::check($devotee, $validated['code'])) {
            throw ValidationException::withMessages(['code' => 'That code is wrong or has expired. Ask for a new one.']);
        }

        DevoteePasswordReset::setPassword($devotee, $validated['password']);
        $devotee->forceFill(['last_seen_at' => now()])->saveQuietly();

        return response()->json(['data' => [
            'devotee' => new DevoteeResource($devotee),
            'token' => $devotee->createToken('app')->plainTextToken,
        ]]);
    }
}
