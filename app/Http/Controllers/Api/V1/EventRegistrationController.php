<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\EventStatus;
use App\Enums\TempleStatus;
use App\Http\Controllers\Api\V1\Concerns\BuildsCheckout;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\EventRegistrationResource;
use App\Models\EventRegistration;
use App\Models\Payment;
use App\Models\TempleEvent;
use App\Support\AppConfig;
use App\Support\Events\EventRegistrations;
use App\Support\Payments\Payments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Joining an event from the app ("I'll join", or tickets), and the
 * devotee's own tickets. Everything read back is scoped to the signed-in
 * devotee in the queries here.
 */
class EventRegistrationController extends Controller
{
    use BuildsCheckout;

    public function __construct(protected Payments $payments, protected EventRegistrations $registrations) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->registrations->expireOverdue($request->user()->eventRegistrations()->getQuery());

        return EventRegistrationResource::collection(
            $request->user()->eventRegistrations()
                ->with(['event', 'temple:id,slug,name,city', 'payment'])
                ->orderByDesc('occurs_on')
                ->orderByDesc('id')
                ->paginate(50)
        );
    }

    public function store(Request $request, int $event): JsonResponse
    {
        $record = TempleEvent::query()
            ->where('status', EventStatus::Published)
            ->whereHas('temple', fn ($q) => $q->where('status', TempleStatus::Published))
            ->find($event) ?? throw new NotFoundHttpException;

        $validated = $request->validate([
            'occurs_on' => ['nullable', 'date_format:Y-m-d'],
            'people' => ['nullable', 'integer', 'min:1', 'max:500'],
            'devotee_name' => ['nullable', 'string', 'max:120'],
            'devotee_phone' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+ ()-]{6,20}$/'],
            'gateway' => ['nullable', 'string', Rule::in(array_keys(Payment::GATEWAYS))],
            'platform' => ['nullable', Rule::in(['android', 'ios', 'web'])],
            'mode' => ['nullable', Rule::in(['sdk', 'web'])],
        ]);

        $gateway = $validated['gateway'] ?? null;
        if ($record->amountPaiseFor((int) ($validated['people'] ?? 1)) > 0) {
            $config = AppConfig::payments($validated['platform'] ?? 'android');
            abort_unless($config['temple_payments'], 403, 'Payments are not open on this device yet. Buy tickets at the temple for now.');
            $gateway ??= $config['default_gateway'];
        }

        $registration = $this->registrations->join($request->user(), $record, $validated, $gateway);

        return response()->json([
            'data' => (new EventRegistrationResource($registration))->resolve($request),
            'checkout' => $registration->payment === null
                ? null
                : $this->checkoutPayload($registration->payment, ($validated['mode'] ?? 'web') === 'sdk'),
        ], 201);
    }

    public function show(Request $request, string $reference): JsonResponse
    {
        $registration = $this->mine($request, $reference);

        if ($registration->payment !== null && ! $registration->payment->isSettled()) {
            try {
                $this->payments->reconcile($registration->payment);
                $registration->refresh();
            } catch (Throwable $e) {
                Log::warning('Ticket payment reconcile failed', ['ticket' => $registration->reference, 'error' => $e->getMessage()]);
            }
        }

        return response()->json(['data' => (new EventRegistrationResource($registration->load(['event', 'temple', 'payment'])))->resolve($request)]);
    }

    public function pay(Request $request, string $reference): JsonResponse
    {
        $validated = $request->validate([
            'gateway' => ['nullable', 'string', Rule::in(array_keys(Payment::GATEWAYS))],
            'platform' => ['nullable', Rule::in(['android', 'ios', 'web'])],
            'mode' => ['nullable', Rule::in(['sdk', 'web'])],
        ]);

        $config = AppConfig::payments($validated['platform'] ?? 'android');
        abort_unless($config['temple_payments'], 403, 'Payments are not open on this device yet.');

        $registration = $this->registrations->retryPayment($this->mine($request, $reference), $validated['gateway'] ?? null);

        return response()->json([
            'data' => (new EventRegistrationResource($registration))->resolve($request),
            'checkout' => $registration->isLive() || $registration->payment === null
                ? null
                : $this->checkoutPayload($registration->payment, ($validated['mode'] ?? 'web') === 'sdk'),
        ]);
    }

    public function cancel(Request $request, string $reference): JsonResponse
    {
        $registration = $this->mine($request, $reference);

        abort_unless($registration->canBeCancelledByDevotee(), 422, 'This can no longer be cancelled.');

        $this->registrations->cancel($registration, 'devotee', $request->input('reason'));

        return response()->json(['data' => (new EventRegistrationResource($registration->fresh(['event', 'temple', 'payment'])))->resolve($request)]);
    }

    protected function mine(Request $request, string $reference): EventRegistration
    {
        $this->registrations->expireOverdue($request->user()->eventRegistrations()->getQuery()->where('reference', strtoupper($reference)));

        return $request->user()->eventRegistrations()
            ->with(['event', 'temple', 'payment'])
            ->where('reference', strtoupper($reference))
            ->first() ?? throw new NotFoundHttpException;
    }
}
