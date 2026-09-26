<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\BookingStatus;
use App\Http\Controllers\Api\V1\Concerns\BuildsCheckout;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\PujaBookingResource;
use App\Models\Payment;
use App\Models\PujaBooking;
use App\Models\Temple;
use App\Models\TemplePuja;
use App\Support\AppConfig;
use App\Support\Bookings\PujaBookings;
use App\Support\Payments\Payments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Booking a puja, seva or prasadam through the app.
 *
 * A booking is made against a temple's own listing, so it is created under
 * the temple's URL. Everything read back is the signed-in devotee's, scoped
 * in the queries here rather than by anything the caller sends.
 */
class PujaBookingController extends Controller
{
    use BuildsCheckout;

    public function __construct(protected Payments $payments, protected PujaBookings $bookings) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return PujaBookingResource::collection(
            $request->user()->pujaBookings()
                ->with(['puja', 'temple:id,slug,name,city', 'payment'])
                ->orderByDesc('booked_for')
                ->orderByDesc('id')
                ->paginate(50)
        );
    }

    /**
     * Places the booking. A free seva is confirmed at once; a priced one
     * answers with the same checkout the plans use, and is confirmed when
     * the gateway confirms the money.
     */
    public function store(Request $request, Temple $temple, TemplePuja $puja): JsonResponse
    {
        // The route says both; a puja from another temple is a 404, not a
        // booking against whichever temple was named.
        if ($puja->temple_id !== $temple->getKey() || ! $puja->is_published) {
            throw new NotFoundHttpException();
        }

        $validated = $request->validate([
            'booked_for' => ['required', 'date_format:Y-m-d'],
            'people' => ['nullable', 'integer', 'min:1', 'max:500'],
            'devotee_name' => ['nullable', 'string', 'max:120'],
            'devotee_phone' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+ ()-]{6,20}$/'],
            'gotram' => ['nullable', 'string', 'max:80'],
            'nakshatram' => ['nullable', 'string', 'max:80'],
            'note' => ['nullable', 'string', 'max:500'],
            'gateway' => ['nullable', 'string', Rule::in(array_keys(Payment::GATEWAYS))],
            'platform' => ['nullable', Rule::in(['android', 'ios', 'web'])],
            'mode' => ['nullable', Rule::in(['sdk', 'web'])],
        ]);

        $gateway = $validated['gateway'] ?? null;

        if ($puja->amountPaiseFor((int) ($validated['people'] ?? 1)) > 0) {
            $config = AppConfig::payments($validated['platform'] ?? 'android');
            abort_unless($config['enabled'], 403, 'Payments are not open on this device yet. Book at the temple counter for now.');
            $gateway ??= $config['default_gateway'];
        }

        $booking = $this->bookings->place($request->user(), $puja, $validated, $gateway);

        return response()->json([
            'data' => (new PujaBookingResource($booking))->resolve($request),
            // Null for a free seva: nothing to pay, already confirmed.
            'checkout' => $booking->payment === null
                ? null
                : $this->checkoutPayload($booking->payment, ($validated['mode'] ?? 'web') === 'sdk'),
        ], 201);
    }

    /** One booking, with its payment asked about if it is still open. */
    public function show(Request $request, string $reference): JsonResponse
    {
        $booking = $this->mine($request, $reference);

        if ($booking->payment !== null && ! $booking->payment->isSettled()) {
            try {
                $this->payments->reconcile($booking->payment);
                $booking->refresh();
            } catch (Throwable $e) {
                Log::warning('Booking payment reconcile failed', ['booking' => $booking->reference, 'error' => $e->getMessage()]);
            }
        }

        return response()->json(['data' => (new PujaBookingResource($booking->load(['puja', 'temple', 'payment'])))->resolve($request)]);
    }

    public function cancel(Request $request, string $reference): JsonResponse
    {
        $booking = $this->mine($request, $reference);

        if (! $booking->canBeCancelledByDevotee()) {
            abort(422, $booking->status === BookingStatus::Verified
                ? 'This booking was already received at the temple.'
                : 'This booking can no longer be cancelled.');
        }

        $this->bookings->cancel($booking, 'devotee', $request->input('reason'));

        return response()->json(['data' => (new PujaBookingResource($booking->fresh(['puja', 'temple', 'payment'])))->resolve($request)]);
    }

    protected function mine(Request $request, string $reference): PujaBooking
    {
        return $request->user()->pujaBookings()
            ->with(['puja', 'temple', 'payment'])
            ->where('reference', strtoupper($reference))
            ->first() ?? throw new NotFoundHttpException();
    }
}
