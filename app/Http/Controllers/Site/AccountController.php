<?php

namespace App\Http\Controllers\Site;

use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Models\Devotee;
use App\Models\State;
use App\Models\Temple;
use App\Support\Bookings\PujaBookings;
use App\Support\DevoteeAccount;
use App\Support\Events\EventRegistrations;
use App\Support\Locales;
use App\Support\Seo;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A devotee's own pages on the website: bookings and tickets with the code
 * the counter scans, offerings, saved temples, the temple passport and the
 * profile. Never indexed.
 */
class AccountController extends Controller
{
    public function index(Request $request, PujaBookings $bookings): View
    {
        $devotee = $request->user();
        $bookings->expireOverdue($devotee->pujaBookings()->getQuery());

        return $this->page('site.account.index', 'My account', [
            'devotee' => $devotee,
            'upcoming' => $devotee->pujaBookings()->with(['puja', 'temple'])
                ->whereDate('booked_for', '>=', now()->toDateString())
                ->whereIn('status', [BookingStatus::Confirmed->value, BookingStatus::PendingPayment->value])
                ->orderBy('booked_for')->limit(3)->get(),
            'stamps' => $devotee->stampCount(),
            'visited' => $devotee->templesVisitedCount(),
            'saved' => $devotee->savedTemples()->count(),
        ]);
    }

    public function bookings(Request $request, PujaBookings $bookings, EventRegistrations $registrations): View
    {
        $devotee = $request->user();
        $bookings->expireOverdue($devotee->pujaBookings()->getQuery());
        $registrations->expireOverdue($devotee->eventRegistrations()->getQuery());

        return $this->page('site.account.bookings', 'My bookings', [
            'bookings' => $devotee->pujaBookings()->with(['puja', 'temple'])->orderByDesc('booked_for')->orderByDesc('id')->limit(100)->get(),
            'tickets' => $devotee->eventRegistrations()->with(['event', 'temple'])->orderByDesc('id')->limit(100)->get(),
        ]);
    }

    public function booking(Request $request, PujaBookings $bookings, string $reference): View
    {
        $booking = $this->findBooking($request, $bookings, $reference);

        return $this->page('site.account.booking', 'Booking '.$booking->reference, [
            'booking' => $booking,
            'qr' => $booking->isLive() ? self::qr($booking->qrUrl()) : null,
        ]);
    }

    public function payBooking(Request $request, PujaBookings $bookings, string $reference): RedirectResponse
    {
        $booking = $bookings->retryPayment($this->findBooking($request, $bookings, $reference));

        if ($booking->isLive() || $booking->payment === null) {
            return redirect()->to(Seo::url('account/bookings/'.$booking->reference));
        }

        $booking->payment->forceFill(['meta' => ['client' => 'web'] + ($booking->payment->meta ?? [])])->save();

        return redirect()->away(BookingController::checkoutUrl($booking->payment));
    }

    public function cancelBooking(Request $request, PujaBookings $bookings, string $reference): RedirectResponse
    {
        $booking = $this->findBooking($request, $bookings, $reference);

        if (! $booking->canBeCancelledByDevotee()) {
            return back()->withErrors(['booking' => $booking->status === BookingStatus::Verified
                ? 'This booking was already received at the temple.'
                : 'This booking can no longer be cancelled.']);
        }

        $bookings->cancel($booking, 'devotee', $request->input('reason'));

        return redirect()->to(Seo::url('account/bookings/'.$booking->reference))->with('status', 'Your booking is cancelled.');
    }

    public function ticket(Request $request, string $reference): View
    {
        $ticket = $request->user()->eventRegistrations()->with(['event', 'temple'])
            ->where('reference', strtoupper($reference))->first() ?? throw new NotFoundHttpException;

        return $this->page('site.account.ticket', 'Ticket '.$ticket->reference, [
            'ticket' => $ticket,
            'qr' => $ticket->isLive() ? self::qr($ticket->qrUrl()) : null,
        ]);
    }

    public function donations(Request $request): View
    {
        return $this->page('site.account.donations', 'My offerings', [
            'donations' => $request->user()->donations()->with('temple:id,slug,name,city')->latest()->limit(200)->get(),
        ]);
    }

    public function saved(Request $request): View
    {
        return $this->page('site.account.saved', 'Saved temples', [
            'temples' => $request->user()->savedTemples()->published()
                ->with(['deity:id,name', 'state:id,name', 'primaryPhoto'])
                ->orderByPivot('created_at', 'desc')->get(),
        ]);
    }

    /** Save or unsave a temple, from its page. */
    public function toggleSaved(Request $request, string $slug): RedirectResponse
    {
        $temple = Temple::query()->published()->where('slug', $slug)->first() ?? throw new NotFoundHttpException;
        $saved = $request->user()->savedTemples();

        if ($saved->whereKey($temple->getKey())->exists()) {
            $request->user()->savedTemples()->detach($temple->getKey());
            $message = 'Removed from your saved temples.';
        } else {
            $request->user()->savedTemples()->syncWithoutDetaching([$temple->getKey()]);
            $message = 'Saved. Find it under My account → Saved temples.';
        }

        return redirect()->to(Seo::url('temples/'.$temple->slug))->with('status', $message);
    }

    public function passport(Request $request): View
    {
        $devotee = $request->user();

        return $this->page('site.account.passport', 'My temple passport', [
            'devotee' => $devotee,
            'visits' => $devotee->visits()->with(['temple:id,slug,name,city,state_id', 'temple.state:id,name'])
                ->orderByDesc('visited_on')->orderByDesc('id')->limit(300)->get(),
            'stamps' => $devotee->stampCount(),
            'qr' => self::qr(\App\Support\PassportQr::url($devotee)),
        ]);
    }

    public function profile(Request $request): View
    {
        return $this->page('site.account.profile', 'My profile', [
            'devotee' => $request->user(),
            'states' => State::query()->orderBy('name')->get(['id', 'name']),
            'locales' => Locales::supported(),
        ]);
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        /** @var Devotee $devotee */
        $devotee = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('devotees', 'email')->ignore($devotee->id)],
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^\+?[0-9]{7,15}$/', Rule::unique('devotees', 'phone')->ignore($devotee->id)],
            'locale' => ['nullable', 'string', Rule::in(array_keys(Locales::supported()))],
            'home_state_id' => ['nullable', 'integer', 'exists:states,id'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
        ]);

        if (($validated['email'] ?? null) !== $devotee->email) {
            $devotee->email_verified_at = null;
        }
        if (($validated['phone'] ?? null) !== $devotee->phone) {
            $devotee->phone_verified_at = null;
        }

        $devotee->fill(array_filter($validated, fn ($v, $k) => $k !== 'locale' || $v !== null, ARRAY_FILTER_USE_BOTH))->save();

        return redirect()->to(Seo::url('account/profile'))->with('status', 'Your profile is saved.');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $devotee = $request->user();

        $request->validate([
            // An account made with Google has no password to confirm yet.
            'current_password' => [filled($devotee->password) ? 'required' : 'nullable', 'string'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
        ]);

        if (filled($devotee->password) && ! \Illuminate\Support\Facades\Hash::check((string) $request->input('current_password'), $devotee->password)) {
            return back()->withErrors(['current_password' => 'Your current password is not right.']);
        }

        $devotee->forceFill(['password' => $request->input('password')])->save();

        return redirect()->to(Seo::url('account/profile'))->with('status', 'Your password is changed.');
    }

    /** Delete the account, as Profile → Delete account in the app. */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate(['confirm' => ['required', 'in:DELETE']], ['confirm.in' => 'Type DELETE to confirm.']);

        DevoteeAccount::delete($request->user());
        Auth::guard('devotee_web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->to(Seo::url('/'))->with('status', 'Your account is deleted.');
    }

    protected function findBooking(Request $request, PujaBookings $bookings, string $reference): \App\Models\PujaBooking
    {
        $bookings->expireOverdue($request->user()->pujaBookings()->getQuery()->where('reference', strtoupper($reference)));

        return $request->user()->pujaBookings()->where('reference', strtoupper($reference))
            ->with(['puja', 'temple', 'payment'])->first() ?? throw new NotFoundHttpException;
    }

    /** A QR code as inline SVG, for the code the temple counter scans. */
    public static function qr(string $data): string
    {
        return (new QRCode(new QROptions([
            'outputType' => QROutputInterface::MARKUP_SVG,
            'outputBase64' => false,
            'eccLevel' => EccLevel::M,
            'addQuietzone' => true,
            'svgAddXmlHeader' => false,
        ])))->render($data);
    }

    /** @param  array<string, mixed>  $data */
    protected function page(string $view, string $title, array $data): View
    {
        return view($view, $data + [
            'title' => $title,
            'description' => $title,
            'canonical' => Seo::url(request()->path()),
            'noindex' => true,
        ]);
    }
}
