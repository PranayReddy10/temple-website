<?php

namespace App\Http\Controllers\Api\V1\Trust;

use App\Enums\BookingStatus;
use App\Http\Controllers\Api\V1\Trust\Concerns\ScopesToTrustTemples;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\EventRegistrationResource;
use App\Http\Resources\V1\PublicPassportResource;
use App\Http\Resources\V1\PujaBookingResource;
use App\Models\Devotee;
use App\Models\EventRegistration;
use App\Models\PujaBooking;
use App\Support\BookingQr;
use App\Support\Bookings\PujaBookings;
use App\Support\DevotionalClock;
use App\Support\Events\EventRegistrations;
use App\Support\Finance\Settlements;
use App\Support\PassportQr;
use App\Support\StaffCheckIn;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Seva bookings at the counter: who is coming, and receiving them.
 *
 * The same rules as the portal's Scan booking page (ScansBookings): a code
 * is looked up only among this account's temples, so another temple's code
 * reads as unknown, and verifying is PujaBookings::verify, which refuses a
 * code the second time.
 */
class TrustBookingController extends Controller
{
    use ScopesToTrustTemples;

    public function index(Request $request, int $temple): JsonResponse
    {
        $record = $this->managedTemple($request, $temple);

        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            // One status, or "successful" (confirmed and received) which is
            // what the counter looks at by default, or "all".
            'status' => ['nullable', 'string', Rule::in([...array_map(fn (BookingStatus $s) => $s->value, BookingStatus::cases()), 'successful', 'all'])],
            'puja_id' => ['nullable', 'integer'],
            // Name, phone number (or part of it) or reference.
            'q' => ['nullable', 'string', 'max:60'],
        ]);
        $q = trim((string) ($validated['q'] ?? ''));

        $page = $record->pujaBookings()
            ->with(['puja', 'temple', 'payment'])
            ->when(mb_strlen($q) >= 2, fn (Builder $query) => $query->tap(self::matching($q, partial: true)))
            ->when($validated['date'] ?? null, fn (Builder $q, string $d) => $q->whereDate('booked_for', $d))
            ->when(($validated['status'] ?? 'all') !== 'all', fn (Builder $q) => $validated['status'] === 'successful'
                ? $q->whereIn('status', [BookingStatus::Confirmed->value, BookingStatus::Verified->value])
                : $q->where('status', $validated['status']))
            ->when($validated['puja_id'] ?? null, fn (Builder $q, int $p) => $q->where('temple_puja_id', $p))
            // Latest on top: the newest day first, and within a day the
            // booking made last.
            ->orderByDesc('booked_for')
            ->latest('id')
            ->paginate(50);

        // A day's totals ride along, so the list can say how many are
        // coming and what they paid without adding up one page of it.
        return PujaBookingResource::collection($page)
            ->additional(isset($validated['date']) ? ['summary' => app(Settlements::class)->day($record, $validated['date'])] : [])
            ->response();
    }

    /** A scanned code, or a reference typed from the devotee's screen. */
    public function scan(Request $request): JsonResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'max:500']]);

        $booking = $this->find($request, $validated['code']);

        if ($booking === null) {
            // An event ticket carries the same kind of code.
            $ticket = $this->findTicket($request, $validated['code']);
            if ($ticket !== null) {
                return response()->json(['data' => [
                    'booking' => (new EventRegistrationResource($ticket))->resolve($request),
                    'outcome' => $ticket->isVerified() ? EventRegistrations::ALREADY_VERIFIED : null,
                ]]);
            }

            return response()->json(['message' => 'No booking or ticket at your temples matches this code. It may be for another temple, or it was cancelled.'], 404);
        }

        return response()->json(['data' => [
            'booking' => (new PujaBookingResource($booking))->resolve($request),
            'outcome' => $booking->isVerified() ? PujaBookings::ALREADY_VERIFIED : null,
        ]]);
    }

    public function verify(Request $request, PujaBookings $bookings, EventRegistrations $tickets): JsonResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'max:500']]);

        $booking = $this->find($request, $validated['code']);

        if ($booking === null && ($ticket = $this->findTicket($request, $validated['code'])) !== null) {
            try {
                $result = $tickets->verify($ticket, $this->trustUser($request));
            } catch (AuthorizationException $e) {
                abort(403, $e->getMessage());
            }

            return response()->json(['data' => [
                'booking' => (new EventRegistrationResource($result['registration']->load(['event', 'temple', 'payment'])))->resolve($request),
                'outcome' => $result['outcome'],
            ]]);
        }

        abort_if($booking === null, 404, 'Scan the code again.');

        try {
            $result = $bookings->verify($booking, $this->trustUser($request));
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        }

        return response()->json(['data' => [
            'booking' => (new PujaBookingResource($result['booking']->load(['puja', 'temple', 'payment'])))->resolve($request),
            'outcome' => $result['outcome'],
        ]]);
    }

    /** A devotee's passport, from the code they showed at the gate. */
    public function passport(Request $request): JsonResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'max:500']]);

        $token = PassportQr::parse($validated['code']);
        $devotee = $token === null ? null : Devotee::findByPassportCode($token);

        if ($devotee === null) {
            return response()->json(['message' => 'No passport matches this code. The devotee may have reset it; ask them to show it again.'], 404);
        }

        return response()->json(['data' => (new PublicPassportResource($devotee->load('homeState:id,name')))->resolve($request)]);
    }

    /**
     * Marks the devotee whose passport was scanned as visited today, at one
     * of this account's temples: the stamp lands in their passport, verified
     * and signed by the counter. The same rule as the portal's Scan passport
     * (StaffCheckIn), so a temple the account does not manage is refused.
     */
    public function markVisited(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:500'],
            'temple_id' => ['required', 'integer'],
        ]);

        $token = PassportQr::parse($validated['code']);
        $devotee = $token === null ? null : Devotee::findByPassportCode($token);
        abort_if($devotee === null, 404, 'Scan the passport again.');

        $temple = $this->trustUser($request)->temples()->whereKey($validated['temple_id'])->first();
        abort_if($temple === null, 404, 'You can mark visits only at temples you manage.');

        try {
            $result = StaffCheckIn::mark($devotee, $temple, $this->trustUser($request));
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        }

        return response()->json(['data' => [
            'outcome' => $result['outcome'],
            'message' => match ($result['outcome']) {
                StaffCheckIn::CREATED => $devotee->name.' is marked as visited today. The stamp is in their passport.',
                StaffCheckIn::VERIFIED => 'Their visit today is now verified.',
                default => $devotee->name.' already has today\'s stamp for '.$temple->name.'.',
            },
            'temple' => ['id' => $temple->getKey(), 'name' => $temple->name],
        ]]);
    }

    /**
     * A devotee at the counter without their phone: find the seva booking
     * or event ticket by its reference, the mobile number given when
     * booking (or on their account), or their name. Today's first, then the
     * days ahead, then the rest; only at the temples this account manages.
     */
    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate(['q' => ['required', 'string', 'min:3', 'max:60']]);
        $q = trim($validated['q']);
        $ids = $this->bookableTempleIds($request);
        $today = DevotionalClock::now()->toDateString();
        $phone = self::phoneIn($q);
        $match = self::matching($q);

        $order = fn (string $dateColumn) => fn (Builder $query) => $query
            ->orderByRaw("case when {$dateColumn} = ? then 0 when {$dateColumn} > ? then 1 else 2 end", [$today, $today])
            ->orderBy($dateColumn);

        $bookings = PujaBooking::query()
            ->with(['puja', 'temple', 'payment'])
            ->when($ids !== null, fn ($query) => $query->whereIn('temple_id', $ids))
            ->tap($match)->tap($order('booked_for'))
            ->limit(30)->get();

        $tickets = EventRegistration::query()
            ->with(['event', 'temple', 'payment'])
            ->when($ids !== null, fn ($query) => $query->whereIn('temple_id', $ids))
            ->tap($match)->tap($order('occurs_on'))
            ->limit(30)->get();

        $rows = collect()
            ->concat($bookings->map(fn (PujaBooking $b) => (new PujaBookingResource($b))->resolve($request)))
            ->concat($tickets->map(fn (EventRegistration $t) => (new EventRegistrationResource($t))->resolve($request)))
            ->sortBy(fn (array $r) => [($r['booked_for'] ?? '') === $today ? 0 : (($r['booked_for'] ?? '') > $today ? 1 : 2), $r['booked_for'] ?? ''])
            ->values();

        return response()->json(['data' => $rows, 'meta' => ['matched_by' => $phone !== null ? 'phone' : 'reference_or_name']]);
    }

    /** The last ten digits of a typed phone number, or null when it is not one (references have letters). */
    protected static function phoneIn(string $q, int $min = 6): ?string
    {
        $digits = preg_replace('/\D/', '', $q);

        return strlen($digits) >= $min && preg_match('/[A-Za-z]/', $q) !== 1 ? substr($digits, -10) : null;
    }

    /**
     * Bookings or tickets a typed search means: by reference, by the phone
     * number given when booking or on the account (compared digit by digit,
     * however it was saved), or by name. With $partial, part of a number is
     * enough (the list on screen); otherwise it must end the number.
     */
    protected static function matching(string $q, bool $partial = false): \Closure
    {
        $q = trim($q);
        $phone = self::phoneIn($q, $partial ? 4 : 6);
        $ref = strtoupper(preg_replace('/\s+/', '', $q));
        $like = $partial ? '%'.$phone.'%' : '%'.$phone;

        return function (Builder $query) use ($phone, $ref, $q, $like): void {
            $query->where(function (Builder $w) use ($phone, $ref, $q, $like): void {
                $w->where('reference', $ref)->orWhere('reference', 'like', $ref.'%');
                if ($phone !== null) {
                    // Numbers are stored as typed ("+91 98480 22338"); compare digits.
                    $digitsOf = fn (string $column) => "replace(replace(replace(replace(replace({$column}, ' ', ''), '-', ''), '+', ''), '(', ''), ')', '')";
                    $w->orWhereRaw($digitsOf('devotee_phone').' like ?', [$like])
                        ->orWhereHas('devotee', fn (Builder $d) => $d->whereRaw($digitsOf('phone').' like ?', [$like]));
                } elseif (mb_strlen($q) >= 2 && ! preg_match('/\d/', $q)) {
                    $w->orWhere('devotee_name', 'like', '%'.$q.'%');
                }
            });
        };
    }

    protected function findTicket(Request $request, string $code): ?EventRegistration
    {
        $ids = $this->bookableTempleIds($request);
        $query = EventRegistration::query()
            ->with(['event', 'temple', 'payment'])
            ->when($ids !== null, fn ($q) => $q->whereIn('temple_id', $ids));

        $token = BookingQr::parse($code);

        return $token === null
            ? $query->where('reference', strtoupper(trim($code)))->first()
            : $query->where('code', $token)->first();
    }

    protected function find(Request $request, string $code): ?PujaBooking
    {
        $ids = $this->bookableTempleIds($request);
        $query = PujaBooking::query()
            ->with(['puja', 'temple', 'payment'])
            ->when($ids !== null, fn ($q) => $q->whereIn('temple_id', $ids));

        $token = BookingQr::parse($code);

        return $token === null
            ? $query->where('reference', strtoupper(trim($code)))->first()
            : $query->where('code', $token)->first();
    }
}
