<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\BuildsCheckout;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Temple;
use App\Models\TempleDonation;
use App\Support\AppConfig;
use App\Support\Donations\Donations;
use App\Support\Payments\Payments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Online hundi: giving to a temple from the app, and the devotee's own
 * receipts. A gift counts once the gateway confirms the money.
 */
class DonationController extends Controller
{
    use BuildsCheckout;

    public function __construct(protected Payments $payments, protected Donations $donations) {}

    public function index(Request $request): JsonResponse
    {
        $rows = $request->user()->donations()->with('temple:id,slug,name,city')->limit(200)->get();

        return response()->json(['data' => $rows->map(fn (TempleDonation $d): array => $d->toDevoteeArray())->values()]);
    }

    public function store(Request $request, Temple $temple): JsonResponse
    {
        $validated = $request->validate([
            // Rupees, whole or with paise.
            'amount' => ['required', 'numeric', 'min:10', 'max:500000'],
            'purpose' => ['nullable', Rule::in(array_keys(TempleDonation::PURPOSES))],
            'donor_name' => ['nullable', 'string', 'max:120'],
            'is_anonymous' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:300'],
            'gateway' => ['nullable', 'string', Rule::in(array_keys(Payment::GATEWAYS))],
            'platform' => ['nullable', Rule::in(['android', 'ios', 'web'])],
            'mode' => ['nullable', Rule::in(['sdk', 'web'])],
        ]);

        $config = AppConfig::payments($validated['platform'] ?? 'android');
        abort_unless($config['enabled'], 403, 'Online payments are not open on this device yet. Please give at the temple for now.');

        $donation = $this->donations->give($request->user(), $temple, [
            'amount_paise' => (int) round(((float) $validated['amount']) * 100),
            'purpose' => $validated['purpose'] ?? null,
            'donor_name' => $validated['donor_name'] ?? null,
            'is_anonymous' => $validated['is_anonymous'] ?? false,
            'note' => $validated['note'] ?? null,
        ], $validated['gateway'] ?? $config['default_gateway']);

        return response()->json([
            'data' => $donation->toDevoteeArray(),
            'checkout' => $this->checkoutPayload($donation->payment, ($validated['mode'] ?? 'web') === 'sdk'),
        ], 201);
    }

    /** A receipt, with its payment asked about if it is still open. */
    public function show(Request $request, string $reference): JsonResponse
    {
        $donation = $request->user()->donations()->with(['temple', 'payment'])->where('reference', strtoupper($reference))->first()
            ?? throw new NotFoundHttpException;

        if ($donation->payment !== null && ! $donation->payment->isSettled()) {
            try {
                $this->payments->reconcile($donation->payment);
                $donation->refresh();
            } catch (Throwable $e) {
                Log::warning('Donation payment reconcile failed', ['donation' => $donation->reference, 'error' => $e->getMessage()]);
            }
        }

        return response()->json(['data' => $donation->toDevoteeArray()]);
    }
}
