<?php

namespace App\Http\Controllers\Api\V1\Trust;

use App\Enums\BookingStatus;
use App\Http\Controllers\Api\V1\Trust\Concerns\ScopesToTrustTemples;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\PublicPassportResource;
use App\Http\Resources\V1\PujaBookingResource;
use App\Models\Devotee;
use App\Models\PujaBooking;
use App\Support\BookingQr;
use App\Support\Bookings\PujaBookings;
use App\Support\PassportQr;
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
            'status' => ['nullable', Rule::enum(BookingStatus::class)],
            'puja_id' => ['nullable', 'integer'],
        ]);

        $page = $record->pujaBookings()
            ->with(['puja', 'temple', 'payment'])
            ->when($validated['date'] ?? null, fn (Builder $q, string $d) => $q->whereDate('booked_for', $d))
            ->when($validated['status'] ?? null, fn (Builder $q, string $s) => $q->where('status', $s))
            ->when($validated['puja_id'] ?? null, fn (Builder $q, int $p) => $q->where('temple_puja_id', $p))
            ->orderBy('booked_for')
            ->latest('id')
            ->paginate(50);

        return PujaBookingResource::collection($page)->response();
    }

    /** A scanned code, or a reference typed from the devotee's screen. */
    public function scan(Request $request): JsonResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'max:500']]);

        $booking = $this->find($request, $validated['code']);

        if ($booking === null) {
            return response()->json(['message' => 'No booking at your temples matches this code. It may be for another temple, or it was cancelled.'], 404);
        }

        return response()->json(['data' => [
            'booking' => (new PujaBookingResource($booking))->resolve($request),
            'outcome' => $booking->isVerified() ? PujaBookings::ALREADY_VERIFIED : null,
        ]]);
    }

    public function verify(Request $request, PujaBookings $bookings): JsonResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'max:500']]);

        $booking = $this->find($request, $validated['code']);

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
