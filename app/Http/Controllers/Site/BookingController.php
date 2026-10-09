<?php

namespace App\Http\Controllers\Site;

use App\Enums\EventStatus;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Temple;
use App\Models\TempleDonation;
use App\Models\TempleEvent;
use App\Models\TemplePuja;
use App\Support\AppConfig;
use App\Support\Bookings\PujaBookings;
use App\Support\DevotionalClock;
use App\Support\Donations\Donations;
use App\Support\Events\EventRegistrations;
use App\Support\Seo;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Booking a seva, giving to the hundi and joining a temple's event on the
 * website, for everyone without the Android app (iPhone users above all).
 *
 * The same services as the app's API place the booking, so the rules, the
 * capacity checks and the money are one; a priced booking is paid through
 * the same checkout pages the app opens (/pay/{payment}), which send the
 * devotee back here when the gateway is done.
 */
class BookingController extends Controller
{
    /** /temples/{slug}/sevas: what the temple offers, with prices; indexable. */
    public function sevas(string $slug): View
    {
        $temple = $this->temple($slug);
        $pujas = $temple->pujas()->published()->orderBy('sort_order')->orderBy('name')->get();
        throw_if($pujas->isEmpty(), NotFoundHttpException::class);

        $bookable = $pujas->filter(fn (TemplePuja $p) => $p->isBookableInApp());
        $place = collect([$temple->city, $temple->state?->name])->filter()->implode(', ');

        return view('site.sevas', [
            'temple' => $temple,
            'pujas' => $pujas,
            'place' => $place,
            'events' => $temple->events()->published()->upcoming()->where('registration_enabled', true)->orderBy('starts_on')->limit(6)->get(),
            'title' => ($bookable->isNotEmpty() ? 'Book sevas online at ' : 'Sevas and pujas at ').self::named($temple),
            'description' => 'Pujas and sevas at '.$temple->name.($place !== '' ? ', '.$place : '').': '.$pujas->take(4)->pluck('name')->implode(', ')
                .($bookable->isNotEmpty() ? '. Book and pay online, get a code to show at the counter.' : ', with fees and timings.'),
            'canonical' => Seo::url('temples/'.$temple->slug.'/sevas'),
            'image' => Seo::absolute($temple->primaryPhoto?->mediumUrl()),
        ]);
    }

    /** The booking form for one seva (signed-in devotees). */
    public function book(string $slug, int $puja): View
    {
        $temple = $this->temple($slug);
        $seva = $this->seva($temple, $puja);
        $today = DevotionalClock::now()->startOfDay();

        return view('site.book', [
            'temple' => $temple,
            'seva' => $seva,
            'today' => $today,
            'last' => $seva->lastBookableDate(),
            'devotee' => request()->user(),
            'paymentsOpen' => ! $seva->requiresPayment() || $this->paymentsOpen($temple),
            'title' => 'Book '.$seva->name.' at '.$temple->name,
            'description' => 'Book '.$seva->name.' at '.$temple->name.' online.',
            'canonical' => Seo::url('temples/'.$temple->slug.'/sevas'),
            'noindex' => true,
        ]);
    }

    /** A seva's time slots on one day, for the form (same origin as the page). */
    public function slots(Request $request, string $slug, int $puja): JsonResponse
    {
        $seva = $this->seva($this->temple($slug), $puja);
        $validated = $request->validate(['date' => ['required', 'date_format:Y-m-d']]);
        $date = CarbonImmutable::parse($validated['date'], DevotionalClock::timezone())->startOfDay();

        return response()->json([
            'remaining' => $seva->remainingCapacityOn($date),
            'slots' => collect($seva->slotAvailability($date))->map(fn (array $s): array => [
                'id' => $s['slot']->getKey(),
                'label' => $s['slot']->label(),
                'available' => $s['available'],
                'bookable' => $s['bookable'],
            ])->values(),
        ]);
    }

    public function storeBooking(Request $request, PujaBookings $bookings, string $slug, int $puja): RedirectResponse
    {
        $temple = $this->temple($slug);
        $seva = $this->seva($temple, $puja);

        $validated = $request->validate([
            'booked_for' => ['required', 'date_format:Y-m-d'],
            'slot_id' => ['nullable', 'integer'],
            'people' => ['nullable', 'integer', 'min:1', 'max:500'],
            'devotee_name' => ['nullable', 'string', 'max:120'],
            'devotee_phone' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+ ()-]{6,20}$/'],
            'gotram' => ['nullable', 'string', 'max:80'],
            'nakshatram' => ['nullable', 'string', 'max:80'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $gateway = null;
        if ($seva->amountPaiseFor((int) ($validated['people'] ?? 1)) > 0) {
            $gateway = $this->gateway();
        }

        $booking = $bookings->place($request->user(), $seva, $validated, $gateway);

        if ($booking->payment === null) {
            return redirect()->to(Seo::url('account/bookings/'.$booking->reference))
                ->with('status', 'Your booking is confirmed. Show the code below at the temple counter.');
        }

        return $this->checkout($booking->payment);
    }

    /** /temples/{slug}/donate: the online hundi; indexable where the temple takes offerings. */
    public function donate(string $slug): View
    {
        $temple = $this->temple($slug);
        abort_unless($this->takesDonations($temple), 404);

        return view('site.donate', [
            'temple' => $temple,
            'purposes' => TempleDonation::PURPOSES,
            'devotee' => request()->user('devotee_web'),
            'paymentsOpen' => $this->paymentsOpen($temple),
            'title' => 'Donate online to '.self::named($temple),
            'description' => 'Give to the hundi of '.$temple->name.' online, by UPI or card. The temple receives it directly; you get a receipt.',
            'canonical' => Seo::url('temples/'.$temple->slug.'/donate'),
            'image' => Seo::absolute($temple->primaryPhoto?->mediumUrl()),
        ]);
    }

    public function storeDonation(Request $request, Donations $donations, string $slug): RedirectResponse
    {
        $temple = $this->temple($slug);
        abort_unless($this->takesDonations($temple), 404);

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:10', 'max:500000'],
            'purpose' => ['nullable', Rule::in(array_keys(TempleDonation::PURPOSES))],
            'donor_name' => ['nullable', 'string', 'max:120'],
            'is_anonymous' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        $donation = $donations->give($request->user(), $temple, [
            'amount_paise' => (int) round(((float) $validated['amount']) * 100),
            'purpose' => $validated['purpose'] ?? null,
            'donor_name' => $validated['donor_name'] ?? null,
            'is_anonymous' => $request->boolean('is_anonymous'),
            'note' => $validated['note'] ?? null,
        ], $this->gateway());

        return $this->checkout($donation->payment);
    }

    /** Joining an event (a free registration or a ticket). */
    public function storeEvent(Request $request, EventRegistrations $registrations, int $event): RedirectResponse
    {
        $record = TempleEvent::query()->where('status', EventStatus::Published)
            ->whereHas('temple', fn ($q) => $q->published())
            ->find($event) ?? throw new NotFoundHttpException;

        $validated = $request->validate([
            'occurs_on' => ['nullable', 'date_format:Y-m-d'],
            'people' => ['nullable', 'integer', 'min:1', 'max:500'],
            'devotee_name' => ['nullable', 'string', 'max:120'],
            'devotee_phone' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+ ()-]{6,20}$/'],
        ]);

        $gateway = $record->amountPaiseFor((int) ($validated['people'] ?? 1)) > 0 ? $this->gateway() : null;
        $registration = $registrations->join($request->user(), $record, $validated, $gateway);

        if ($registration->payment === null) {
            return redirect()->to(Seo::url('account/bookings'))->with('status', 'You are registered for '.$record->title.'.');
        }

        return $this->checkout($registration->payment);
    }

    /**
     * Where the checkout pages send a website payment back to: the booking,
     * receipt or ticket it paid for.
     */
    public function returned(Request $request, string $uuid): RedirectResponse
    {
        $payment = $request->user()->payments()->where('uuid', $uuid)->first() ?? throw new NotFoundHttpException;

        $booking = $request->user()->pujaBookings()->where('payment_id', $payment->getKey())->first();
        if ($booking !== null) {
            return redirect()->to(Seo::url('account/bookings/'.$booking->reference));
        }

        if ($request->user()->donations()->where('payment_id', $payment->getKey())->exists()) {
            return redirect()->to(Seo::url('account/donations'))->with('status', $payment->isPaid() ? 'Thank you. Your offering has reached the temple.' : null);
        }

        return redirect()->to(Seo::url('account/bookings'));
    }

    /** The checkout page for a payment, marked as the website's so it comes back here. */
    protected function checkout(Payment $payment): RedirectResponse
    {
        $payment->forceFill(['meta' => ['client' => 'web'] + ($payment->meta ?? [])])->save();

        return redirect()->away(self::checkoutUrl($payment));
    }

    /**
     * The signed checkout link, on temple.darshansaathi.com where the
     * checkout pages live: a signature covers the host, so a link signed
     * for the website's own address would not open there.
     */
    public static function checkoutUrl(Payment $payment): string
    {
        $url = app('url');
        $url->forceRootUrl(rtrim((string) config('app.url'), '/'));

        try {
            return $url->temporarySignedRoute('pay.show', now()->addMinutes(30), ['payment' => $payment]);
        } finally {
            $url->forceRootUrl(null);
        }
    }

    /** "Chilkur Balaji Temple, Hyderabad", without repeating a town already in the name. */
    public static function named(Temple $temple): string
    {
        return $temple->name.($temple->city && ! str_contains(mb_strtolower($temple->name), mb_strtolower($temple->city)) ? ', '.$temple->city : '');
    }

    protected function gateway(): string
    {
        $config = AppConfig::payments('web');
        if (! $config['temple_payments'] || $config['default_gateway'] === null) {
            throw ValidationException::withMessages(['payment' => 'Online payments are not open yet. Please pay at the temple for now.']);
        }

        return $config['default_gateway'];
    }

    protected function paymentsOpen(Temple $temple): bool
    {
        $config = AppConfig::payments('web');

        return $config['temple_payments'] && $config['default_gateway'] !== null && $temple->canCollectPayments();
    }

    protected function takesDonations(Temple $temple): bool
    {
        return (bool) $temple->accepts_donations && $temple->canCollectPayments();
    }

    protected function temple(string $slug): Temple
    {
        return Temple::query()->published()->where('slug', $slug)->with(['state', 'primaryPhoto'])->first()
            ?? throw new NotFoundHttpException;
    }

    protected function seva(Temple $temple, int $puja): TemplePuja
    {
        $seva = $temple->pujas()->published()->whereKey($puja)->first() ?? throw new NotFoundHttpException;
        abort_unless($seva->isBookableInApp(), 404);

        return $seva->setRelation('temple', $temple);
    }
}
