<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Models\Payment;
use App\Support\Payments\Payments;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * What the app needs to take a payment, whatever the payment is for.
 *
 * Plans and seva bookings pay the same way: the gateway's native SDK where
 * it has one, the signed web checkout otherwise. One builder, so a booking
 * cannot drift from a plan in how Razorpay's key or PhonePe's token is
 * handed over.
 */
trait BuildsCheckout
{
    /** Gateways the app pays through natively rather than a web page. */
    public const NATIVE_SDK = ['razorpay', 'cashfree', 'phonepe'];

    /**
     * @return array<string, mixed>
     */
    protected function checkoutPayload(Payment $payment, bool $sdkMode): array
    {
        $sdk = null;
        $sdkError = null;

        if ($sdkMode && in_array($payment->gateway, self::NATIVE_SDK, true)) {
            try {
                $sdk = $this->sdk($payment->load('plan', 'devotee'));
            } catch (Throwable $e) {
                // Said to the app rather than swallowed: the app does not
                // fall back to a web page for a gateway it pays natively.
                $sdkError = $e->getMessage();
                Log::warning('Native checkout could not start', ['payment' => $payment->uuid, 'error' => $sdkError]);
            }
        }

        return [
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
        ];
    }

    protected function paymentsService(): Payments
    {
        return $this->payments ?? app(Payments::class);
    }

    /**
     * What the gateway's SDK needs to open its payment sheet.
     *
     * @return array<string, mixed>
     */
    protected function sdk(Payment $payment): array
    {
        // PhonePe's app SDK has its own order call; its web start is not used.
        $start = $payment->gateway === 'phonepe' ? [] : ($this->paymentsService()->gateway($payment->gateway)->start($payment)['data'] ?? []);
        $devotee = $payment->devotee;

        return match ($payment->gateway) {
            'razorpay' => [
                'gateway' => 'razorpay',
                'key' => $start['key'],
                'order_id' => $start['order_id'],
                'amount_paise' => $payment->amount_paise,
                'currency' => $payment->currency,
                'name' => config('brand.name'),
                'description' => $payment->description(),
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
        $phonepe = $this->paymentsService()->gateway('phonepe');

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

    /** @return array<string, mixed> */
    protected function paymentArray(Payment $p): array
    {
        return [
            'id' => $p->uuid,
            'status' => $p->status,
            'purpose' => $p->purpose,
            'gateway' => $p->gateway,
            'amount_paise' => $p->amount_paise,
            'amount' => $p->amountLabel(),
            'plan' => $p->plan?->name,
            'description' => $p->description(),
            'failure_reason' => $p->failure_reason,
            'paid_at' => $p->paid_at?->toIso8601String(),
            'created_at' => $p->created_at?->toIso8601String(),
        ];
    }
}
