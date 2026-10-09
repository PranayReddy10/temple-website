<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RegisterDevoteeRequest;
use App\Models\Devotee;
use App\Support\Auth\IdTokenVerifier;
use App\Support\Auth\InvalidIdToken;
use App\Support\Auth\KeysUnavailable;
use App\Support\Auth\SocialAccounts;
use App\Support\DevoteePasswordReset;
use App\Support\LoginRecorder;
use App\Support\Seo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

/**
 * Signing in on the website: the same devotee accounts as the app, by
 * email or phone and password, or with Google. A browser session stands
 * in for the app's token.
 */
class AuthController extends Controller
{
    public function showLogin(Request $request): View|RedirectResponse
    {
        $this->rememberNext($request);
        if (Auth::guard('devotee_web')->check()) {
            return redirect()->to(Seo::url('account'));
        }

        return $this->page('site.auth.login', 'Sign in', 'Sign in to book sevas, give to temples and keep your temple passport.');
    }

    public function login(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'identifier' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        // Emails match whatever case they were typed in.
        $identifier = trim($validated['identifier']);
        $devotee = Devotee::query()
            ->whereRaw('LOWER(email) = ?', [mb_strtolower($identifier)])
            ->orWhere('phone', $identifier)
            ->first();

        // One message for "no such account" and "wrong password", so the
        // form cannot be used to find out which numbers are registered.
        if ($devotee === null || blank($devotee->password) || ! Hash::check($validated['password'], $devotee->password)) {
            LoginRecorder::failure('devotee', $identifier, $devotee === null ? 'unknown_account' : 'bad_password', $request);

            throw ValidationException::withMessages(['identifier' => 'These details do not match an account.']);
        }

        return $this->signIn($request, $devotee, $request->boolean('remember'));
    }

    public function showRegister(Request $request): View|RedirectResponse
    {
        $this->rememberNext($request);
        if (Auth::guard('devotee_web')->check()) {
            return redirect()->to(Seo::url('account'));
        }

        return $this->page('site.auth.register', 'Create your account', 'Create a free account to book sevas, give to temples and keep your temple passport.');
    }

    public function register(RegisterDevoteeRequest $request): RedirectResponse
    {
        abort_unless($this->passwordsEnabled(), 403, 'Sign-up with a password is not available. Please continue with Google.');

        $devotee = Devotee::create($request->safe()->only(['name', 'email', 'phone', 'password', 'locale']));

        return $this->signIn($request, $devotee, true, 'Welcome, '.$devotee->name.'. Your account is ready.');
    }

    /**
     * Google's button, in redirect mode: Google posts the signed ID token
     * here from accounts.google.com, so Laravel's CSRF token cannot come
     * with it. Google's own double-submit cookie (g_csrf_token) stands in.
     */
    public function google(Request $request): RedirectResponse
    {
        $cookie = (string) $request->cookie('g_csrf_token');
        if ($cookie === '' || ! hash_equals($cookie, (string) $request->input('g_csrf_token'))) {
            LoginRecorder::failure('devotee', 'google', 'google_csrf_mismatch', $request);

            return redirect()->to(Seo::url('login'))->withErrors(['identifier' => 'The Google sign-in could not be confirmed. Please try again.']);
        }

        abort_unless(SocialAccounts::googleWebClientId() !== null, 403, 'Sign-in with Google is not available.');

        try {
            $claims = IdTokenVerifier::verify((string) $request->input('credential'), IdTokenVerifier::GOOGLE, SocialAccounts::googleAudiences());
        } catch (InvalidIdToken $e) {
            LoginRecorder::failure('devotee', 'google', 'invalid_google_token', $request);

            return redirect()->to(Seo::url('login'))->withErrors(['identifier' => $e->getMessage()]);
        } catch (KeysUnavailable) {
            return redirect()->to(Seo::url('login'))->withErrors(['identifier' => 'Sign-in with Google is unavailable right now. Please try again.']);
        }

        [$devotee, $created] = SocialAccounts::resolve('google_id', $claims, $claims['name'] ?? null);

        return $this->signIn($request, $devotee, true, $created ? 'Welcome, '.$devotee->name.'. Your account is ready.' : null);
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('devotee_web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->to(Seo::url('/'));
    }

    public function showForgot(): View
    {
        abort_unless(DevoteePasswordReset::enabled(), 404);

        return $this->page('site.auth.forgot', 'Forgot your password', 'Get a code by email to set a new password.');
    }

    public function forgot(Request $request): RedirectResponse
    {
        abort_unless(DevoteePasswordReset::enabled(), 404);

        $validated = $request->validate(['email' => ['required', 'email', 'max:255']]);
        $devotee = DevoteePasswordReset::findByEmail($validated['email']);

        if ($devotee !== null && $devotee->is_active) {
            try {
                DevoteePasswordReset::send($devotee);
            } catch (Throwable $e) {
                Log::error('Password reset email failed', ['devotee' => $devotee->id, 'error' => $e->getMessage()]);

                return back()->withInput()->withErrors(['email' => 'We could not send the email right now. Please try again later.']);
            }
        }

        // The same answer whether or not the address has an account.
        return redirect()->to(Seo::url('reset-password').'?'.http_build_query(['email' => $validated['email']]))
            ->with('status', 'If an account uses that email, a code is on its way. It works for '.DevoteePasswordReset::minutes().' minutes.');
    }

    public function showReset(Request $request): View
    {
        abort_unless(DevoteePasswordReset::enabled(), 404);

        return $this->page('site.auth.reset', 'Set a new password', 'Enter the code from your email and a new password.', ['email' => (string) $request->query('email')]);
    }

    public function reset(Request $request): RedirectResponse
    {
        abort_unless(DevoteePasswordReset::enabled(), 404);

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

        return $this->signIn($request, $devotee, false, 'Your password is changed.');
    }

    protected function signIn(Request $request, Devotee $devotee, bool $remember, ?string $message = null): RedirectResponse
    {
        if ($devotee->trashed() || ! $devotee->is_active) {
            LoginRecorder::failure('devotee', $devotee->email ?? (string) $devotee->phone, 'inactive_account', $request);

            throw ValidationException::withMessages(['identifier' => 'This account is no longer active.']);
        }

        // Raises Login, which records the sign-in as for the app's.
        Auth::guard('devotee_web')->login($devotee, $remember);
        $request->session()->regenerate();
        $devotee->forceFill(['last_seen_at' => now()])->saveQuietly();

        $to = (string) $request->session()->pull('url.intended', '');

        return redirect()->to($this->safe($to) ?? Seo::url('account'))->with('status', $message);
    }

    /** ?next=<page>: where to go after signing in ("Sign in to join" on a temple page). */
    protected function rememberNext(Request $request): void
    {
        if (($next = $this->safe((string) $request->query('next'))) !== null) {
            $request->session()->put('url.intended', $next);
        }
    }

    /** Back to where the devotee was, only if it is a page of this website. */
    protected function safe(string $url): ?string
    {
        return $url !== '' && Str::startsWith($url, Seo::url('/')) ? $url : null;
    }

    protected function passwordsEnabled(): bool
    {
        return (bool) setting('auth_password_enabled', null, true);
    }

    /** @param  array<string, mixed>  $data */
    protected function page(string $view, string $title, string $description, array $data = []): View
    {
        return view($view, $data + [
            'title' => $title,
            'description' => $description,
            'canonical' => Seo::url(request()->path()),
            'noindex' => true,
            'passwords' => $this->passwordsEnabled(),
            'resetEnabled' => DevoteePasswordReset::enabled(),
            'googleClientId' => SocialAccounts::googleWebClientId(),
        ]);
    }
}
