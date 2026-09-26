<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\SubscriptionPlan;
use App\Support\AppConfig;
use App\Support\Payments\Payments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/** Plans, buying one, and what the devotee holds. */
class SubscriptionController extends Controller
{
    public function __construct(protected Payments $payments) {}

    public function plans(Request $request): JsonResponse
    {
        $platform = $request->query('platform');

        return response()->json([
            'data' => SubscriptionPlan::query()->active()->get()->map(fn (SubscriptionPlan $p): array => $this->plan($p))->all(),
            'meta' => [
                'payments' => AppConfig::payments(is_string($platform) ? $platform : 'android'),
                'benefits' => collect(SubscriptionPlan::BENEFITS)->map(fn (array $b) => $b[0])->all(),
            ],
        ]);
    }

    public function show(Request $request): JsonResponse
    {
        $devotee = $request->user();
        $current = $devotee->currentSubscription();

        return response()->json(['data' => [
            'entitlements' => $devotee->entitlements(),
            'current' => $current === null ? null : [
                'plan' => $this->plan($current->plan),
                'starts_at' => $current->starts_at->toIso8601String(),
                'ends_at' => $current->ends_at->toIso8601String(),
            ],
            // Renewals bought early start when the current one ends.
            'queued_until' => optional($devotee->subscriptions()->whereNull('cancelled_at')->max('ends_at'), fn ($d) => \Illuminate\Support\Carbon::parse($d)->toIso8601String()),
            'payments' => $devotee->payments()->with('plan')->limit(20)->get()->map(fn (Payment $p): array => $this->paymentArray($p))->all(),
        ]]);
    }

    /**
     * Starts a purchase. Answers with a signed checkout link the app opens
     * in its in-app browser; nothing is charged until the devotee pays there.
     */
    public function checkout(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'plan' => ['required', 'string', Rule::exists('subscription_plans', 'code')->where('is_active', true)],
            'gateway' => ['nullable', 'string', Rule::in(array_keys(Payment::GATEWAYS))],
            'platform' => ['nullable', Rule::in(['android', 'ios', 'web'])],
            // "sdk": the app pays through the gateway's own native SDK where
            // it has one, and only falls back to the web checkout otherwise.
            'mode' => ['nullable', Rule::in(['sdk', 'web'])],
        ]);

        $config = AppConfig::payments($validated['platform'] ?? 'android');

        abort_unless($config['enabled'], 403, 'Plans cannot be bought here yet.');

        $plan = SubscriptionPlan::query()->where('code', $validated['plan'])->firstOrFail();

        try {
            $payment = $this->payments->begin($request->user(), $plan, $validated['gateway'] ?? $config['default_gateway']);
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        $sdk = null;
        $sdkError = null;

        if (($validated['mode'] ?? 'web') === 'sdk' && in_array($payment->gateway, self::NATIVE_SDK, true)) {
            try {
                $sdk = $this->sdk($payment->load('plan', 'devotee'));
            } catch (Throwable $e) {
                // Said to the app rather than swallowed: the app does not
                // fall back to a web page for a gateway it pays natively.
                $sdkError = $e->getMessage();
                Log::warning('Native checkout could not start', ['payment' => $payment->uuid, 'error' => $sdkError]);
            }
        }

        return response()->json(['data' => [
            'payment' => $this->paymentArray($payment->load('plan')),
            // Present when the app should open the gateway's SDK; null means
            // use checkout_url in the system browser tab.
            'sdk' => $sdk,
            // Why sdk is null for a gateway that has one: wrong keys, a
            // missing merchant id, the gateway refusing the order.
            'sdk_error' => $sdkError,
            'checkout_url' => URL::temporarySignedRoute('pay.show', now()->addMinutes(30), ['payment' => $payment]),
            // The in-app browser closes when it reaches this page.
            'done_url' => route('pay.done', ['payment' => $payment]),
        ]], 201);
    }

    /** Gateways the app pays through natively rather than a web page. */
    public const NATIVE_SDK = ['razorpay', 'cashfree', 'phonepe'];

    /**
     * What the gateway's SDK needs to open its payment sheet.
     *
     * @return array<string, mixed>
     */
    protected function sdk(Payment $payment): array
    {
        // PhonePe's app SDK has its own order call; its web start is not used.
        $start = $payment->gateway === 'phonepe' ? [] : ($this->payments->gateway($payment->gateway)->start($payment)['data'] ?? []);
        $devotee = $payment->devotee;

        return match ($payment->gateway) {
            'razorpay' => [
                'gateway' => 'razorpay',
                'key' => $start['key'],
                'order_id' => $start['order_id'],
                'amount_paise' => $payment->amount_paise,
                'currency' => $payment->currency,
                'name' => config('brand.name'),
                'description' => $payment->plan?->name,
                'prefill' => array_filter([
                    'name' => $devotee?->name,
                    'email' => $devotee?->email,
                    'contact' => $devotee?->phone,
                ]),
                'theme_color' => config('brand.colors.saffron.hex'),
            ],
            'cashfree' => [
                'gateway' => 'cashfree',
                'order_id' => $payment->gateway_order_id,
                'session_id' => $start['session_id'],
                'environment' => ($start['mode'] ?? 'sandbox') === 'production' ? 'production' : 'sandbox',
            ],
            'phonepe' => $this->phonePeSdk($payment),
        };
    }

    /** @return array<string, mixed> */
    protected function phonePeSdk(Payment $payment): array
    {
        /** @var \App\Support\Payments\Gateways\PhonePe $phonepe */
        $phonepe = $this->payments->gateway('phonepe');

        $merchantId = $phonepe->merchantId() ?? throw new \RuntimeException('PhonePe merchant id is not set in the admin (Settings → Payments).');
        $order = $phonepe->sdkOrder($payment);

        return [
            'gateway' => 'phonepe',
            'environment' => $phonepe->environment(),
            'merchant_id' => $merchantId,
            'order_id' => $order['order_id'],
            'token' => $order['token'],
            // Ties the app's journey to PhonePe's logs, per their docs.
            'flow_id' => 'devotee'.$payment->devotee_id,
        ];
    }

    /**
     * The app reports how its SDK checkout ended. Nothing here is trusted on
     * its own: Razorpay's result is checked by its signature, and every
     * gateway is asked directly when there is no signature to check.
     */
    public function confirm(Request $request, string $uuid): JsonResponse
    {
        $request->validate([
            'razorpay_payment_id' => ['nullable', 'string', 'max:64'],
            'razorpay_order_id' => ['nullable', 'string', 'max:64'],
            'razorpay_signature' => ['nullable', 'string', 'max:128'],
        ]);

        $payment = $request->user()->payments()->where('uuid', $uuid)->with('plan')->first()
            ?? throw new NotFoundHttpException();

        // A signature for a different order is not this payment's.
        if ($request->filled('razorpay_order_id') && $request->input('razorpay_order_id') !== $payment->gateway_order_id) {
            abort(422, 'That result belongs to another order.');
        }

        try {
            $payment = $this->payments->reconcile($payment, $request->filled('razorpay_signature') ? $request : null)->load('plan');
        } catch (Throwable $e) {
            Log::warning('Payment confirm failed', ['payment' => $payment->uuid, 'error' => $e->getMessage()]);
        }

        return response()->json(['data' => $this->paymentArray($payment) + [
            'entitlements' => $request->user()->entitlements(),
        ]]);
    }

    /** How a payment stands, asking the gateway if it is still open. */
    public function status(Request $request, string $uuid): JsonResponse
    {
        $payment = $request->user()->payments()->where('uuid', $uuid)->with('plan')->first()
            ?? throw new NotFoundHttpException();

        try {
            $payment = $this->payments->reconcile($payment)->load('plan');
        } catch (Throwable $e) {
            Log::warning('Payment reconcile failed', ['payment' => $payment->uuid, 'error' => $e->getMessage()]);
        }

        return response()->json(['data' => $this->paymentArray($payment) + [
            'entitlements' => $request->user()->entitlements(),
        ]]);
    }

    /** @return array<string, mixed> */
    protected function paymentArray(Payment $p): array
    {
        return [
            'id' => $p->uuid,
            'status' => $p->status,
            'gateway' => $p->gateway,
            'amount_paise' => $p->amount_paise,
            'amount' => $p->amountLabel(),
            'plan' => $p->plan?->name,
            'failure_reason' => $p->failure_reason,
            'paid_at' => $p->paid_at?->toIso8601String(),
            'created_at' => $p->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    protected function plan(SubscriptionPlan $p): array
    {
        return [
            'code' => $p->code,
            'name' => $p->name,
            'description' => $p->description,
            'price_paise' => $p->price_paise,
            'price' => $p->priceLabel(),
            'period' => $p->periodLabel(),
            'duration_days' => $p->duration_days,
            'currency' => $p->currency,
            'badge' => $p->badge,
            'benefits' => (object) array_filter((array) $p->benefits, fn ($v) => $v !== null && $v !== false && $v !== 0 && $v !== ''),
        ];
    }

    /** A gateway's server-to-server notification. Always 200 once handled, so it is not retried forever. */
    public function webhook(Request $request, string $gateway): JsonResponse
    {
        abort_unless(array_key_exists($gateway, Payment::GATEWAYS), 404);

        try {
            $result = $this->payments->gateway($gateway)->webhook($request);
        } catch (Throwable $e) {
            Log::warning('Payment webhook failed', ['gateway' => $gateway, 'error' => $e->getMessage()]);

            return response()->json(['ok' => false], 500);
        }

        if ($result !== null) {
            [$payment, $status] = $result;
            $this->payments->apply($payment, $status, $payment->gateway_payment_id);
        }

        return response()->json(['ok' => true]);
    }
}
