<?php

namespace App\Http\Controllers\Api\V1\Trust;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Trust\TrustAccountResource;
use App\Models\User;
use App\Support\LoginRecorder;
use App\Support\TempleTeam\TrustAccounts;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Accounts for the temple trust app.
 *
 * A temple team signs up here and is a temple admin with no temples: the
 * account grants nothing until staff approve a claim on a temple, or approve
 * a temple the team registered. Signing up is therefore safe to leave open,
 * the same way the portal's claim screen is.
 */
class TrustAuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            // Staff confirm a claim by calling the temple; no number, no claim.
            'phone' => ['required', 'string', 'max:20', 'regex:/^[0-9+ ()-]{6,20}$/'],
            'password' => ['required', 'string', Password::min(8)],
        ]);

        // Built field by field rather than from the request, so nothing a
        // caller sends can set a role or an active flag.
        $user = new User;
        $user->name = $validated['name'];
        $user->email = mb_strtolower(trim($validated['email']));
        $user->phone = $validated['phone'];
        $user->password = $validated['password'];
        $user->role = UserRole::TempleAdmin;
        $user->is_active = true;
        $user->save();

        Event::dispatch(new Login('trust', $user, false));

        return response()->json([
            'data' => [
                'account' => (new TrustAccountResource($user))->resolve($request),
                'token' => $user->createToken('trust-app')->plainTextToken,
            ],
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $email = mb_strtolower(trim($validated['email']));
        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        // One message for "no such account" and "wrong password", so the
        // endpoint cannot be used to find out who has an account.
        if ($user === null || ! Hash::check($validated['password'], $user->password)) {
            LoginRecorder::failure('trust', $validated['email'], $user === null ? 'unknown_account' : 'bad_password', $request);

            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        if (! (bool) $user->is_active) {
            LoginRecorder::failure('trust', $validated['email'], 'inactive_account', $request);

            throw ValidationException::withMessages([
                'email' => 'This account is no longer active.',
            ]);
        }

        // Temple teams, and super admins who run the whole platform from
        // their phone. Editors stay in the admin panel.
        if (! $user->isTempleAdmin() && ! $user->isSuperAdmin()) {
            LoginRecorder::failure('trust', $validated['email'], 'staff_account', $request);

            throw ValidationException::withMessages([
                'email' => 'Editor accounts sign in to the admin panel instead.',
            ]);
        }

        Event::dispatch(new Login('trust', $user, false));

        return response()->json([
            'data' => [
                'account' => (new TrustAccountResource($user))->resolve($request),
                'token' => $user->createToken('trust-app')->plainTextToken,
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => (new TrustAccountResource($request->user('trust')))->resolve($request)]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user('trust');

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:120'],
            'phone' => ['sometimes', 'required', 'string', 'max:20', 'regex:/^[0-9+ ()-]{6,20}$/'],
            'current_password' => ['required_with:password', 'string'],
            'password' => ['sometimes', 'required', 'string', Password::min(8)],
        ]);

        if (isset($validated['password']) && ! Hash::check($validated['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'That is not your current password.']);
        }

        foreach (['name', 'phone', 'password'] as $field) {
            if (array_key_exists($field, $validated)) {
                $user->{$field} = $validated[$field];
            }
        }

        $user->save();

        return $this->me($request);
    }

    /**
     * Deletes the signed-in team member's account, as app stores require.
     * Their password confirms it. The temples they ran keep everything they
     * added (timings, sevas, money records); the person is removed: signed
     * out everywhere, their access to temples and open requests withdrawn,
     * and their name, email and phone erased from the account row.
     */
    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user('trust');

        $validated = $request->validate([
            'password' => ['required', 'string'],
            'confirm' => ['required', 'in:DELETE'],
        ], ['confirm.in' => 'Type DELETE to confirm.']);

        if (! Hash::check($validated['password'], (string) $user->password)) {
            throw ValidationException::withMessages(['password' => 'That is not your password.']);
        }
        // Staff accounts are managed by the platform, not deleted from the app.
        abort_unless($user->isTempleAdmin(), 403, 'Staff accounts are closed by a super admin in the admin panel.');

        TrustAccounts::delete($user);

        return response()->json(['data' => ['message' => 'Your account has been deleted.']]);
    }

    /** Revokes only the token that made this request, not every device. */
    public function logout(Request $request): JsonResponse
    {
        $request->user('trust')->currentAccessToken()->delete();

        return response()->json(['data' => ['message' => 'Signed out.']]);
    }
}
