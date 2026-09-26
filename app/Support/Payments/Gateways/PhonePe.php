<?php

namespace App\Support\Payments\Gateways;

use App\Models\Payment;
use App\Models\Setting;
use App\Support\Payments\PaymentGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * PhonePe Standard Checkout (v2): an OAuth token, a pay request that answers
 * with PhonePe's hosted page, and an order-status call that is the only thing
 * trusted to say the payment went through.
 */
class PhonePe implements PaymentGateway
{
    public function code(): string
    {
        return 'phonepe';
    }

    protected function production(): bool
    {
        return setting('payments_phonepe_env') === 'production';
    }

    protected function pgBase(): string
    {
        return $this->production() ? 'https://api.phonepe.com/apis/pg' : 'https://api-preprod.phonepe.com/apis/pg-sandbox';
    }

    protected function authBase(): string
    {
        return $this->production() ? 'https://api.phonepe.com/apis/identity-manager' : 'https://api-preprod.phonepe.com/apis/pg-sandbox';
    }

    protected function token(): string
    {
        $clientId = (string) setting('payments_phonepe_client_id');

        return Cache::remember('phonepe:token:'.md5($clientId.$this->production()), now()->addMinutes(20), function () use ($clientId): string {
            $response = Http::asForm()->timeout(15)->post($this->authBase().'/v1/oauth/token', [
                'client_id' => $clientId,
                'client_version' => (string) setting('payments_phonepe_client_version', null, 1),
                'client_secret' => (string) Setting::secret('payments_phonepe_client_secret'),
                'grant_type' => 'client_credentials',
            ]);

            if (! $response->successful() || blank($response->json('access_token'))) {
                throw new RuntimeException('PhonePe refused the credentials.');
            }

            return $response->json('access_token');
        });
    }

    protected function merchantOrderId(Payment $payment): string
    {
        // Letters, digits, _ and - only, at most 63 characters.
        return 'TP_'.str_replace('-', '', $payment->uuid);
    }

    public function start(Payment $payment): array
    {
        $response = Http::withHeaders(['Authorization' => 'O-Bearer '.$this->token()])->timeout(15)
            ->post($this->pgBase().'/checkout/v2/pay', [
                'merchantOrderId' => $this->merchantOrderId($payment),
                'amount' => $payment->amount_paise,
                'expireAfter' => 1800,
                'paymentFlow' => [
                    'type' => 'PG_CHECKOUT',
                    'message' => $payment->description(),
                    'merchantUrls' => ['redirectUrl' => route('pay.return', ['payment' => $payment, 'gateway' => 'phonepe'])],
                ],
            ]);

        if (! $response->successful() || blank($response->json('redirectUrl'))) {
            throw new RuntimeException('PhonePe could not start the payment: '.($response->json('message') ?? $response->status()));
        }

        $payment->forceFill([
            'gateway_order_id' => $this->merchantOrderId($payment),
            'status' => Payment::PENDING,
            'meta' => array_merge((array) $payment->meta, ['phonepe_order_id' => $response->json('orderId')]),
        ])->save();

        return ['redirect' => $response->json('redirectUrl')];
    }

    public function merchantId(): ?string
    {
        return filled(setting('payments_phonepe_merchant_id')) ? (string) setting('payments_phonepe_merchant_id') : null;
    }

    public function environment(): string
    {
        return $this->production() ? 'PRODUCTION' : 'SANDBOX';
    }

    /**
     * An order for PhonePe's app SDK: answers with PhonePe's order id and
     * the token the SDK opens its payment sheet with. The same
     * merchantOrderId, and the same status call, as the web checkout.
     *
     * @return array{order_id: string, token: string}
     */
    public function sdkOrder(Payment $payment): array
    {
        $meta = (array) $payment->meta;

        // Already created (a retry): PhonePe refuses a second order with the
        // same merchantOrderId, and the token lasts as long as the order.
        if (filled($meta['phonepe_sdk_token'] ?? null) && filled($meta['phonepe_order_id'] ?? null)) {
            return ['order_id' => $meta['phonepe_order_id'], 'token' => $meta['phonepe_sdk_token']];
        }

        $response = Http::withHeaders(['Authorization' => 'O-Bearer '.$this->token()])->timeout(15)
            ->post($this->pgBase().'/checkout/v2/sdk/order', [
                'merchantOrderId' => $this->merchantOrderId($payment),
                'amount' => $payment->amount_paise,
                'expireAfter' => 1800,
                'paymentFlow' => ['type' => 'PG_CHECKOUT', 'message' => $payment->description()],
            ]);

        if (! $response->successful() || blank($response->json('token')) || blank($response->json('orderId'))) {
            throw new RuntimeException('PhonePe could not create the order: '.($response->json('message') ?? $response->status()));
        }

        $payment->forceFill([
            'gateway_order_id' => $this->merchantOrderId($payment),
            'status' => Payment::PENDING,
            'meta' => array_merge($meta, ['phonepe_order_id' => $response->json('orderId'), 'phonepe_sdk_token' => $response->json('token')]),
        ])->save();

        return ['order_id' => $response->json('orderId'), 'token' => $response->json('token')];
    }

    public function confirm(Payment $payment, ?Request $request = null): string
    {
        $response = Http::withHeaders(['Authorization' => 'O-Bearer '.$this->token()])->timeout(15)
            ->get($this->pgBase().'/checkout/v2/order/'.$this->merchantOrderId($payment).'/status');

        if (! $response->successful()) {
            return Payment::PENDING;
        }

        return match ($response->json('state')) {
            'COMPLETED' => tap(Payment::PAID, fn () => $payment->gateway_payment_id = $response->json('paymentDetails.0.transactionId')),
            'FAILED' => Payment::FAILED,
            default => Payment::PENDING,
        };
    }

    /** The callback body is not trusted: it only names the order to look up. */
    public function webhook(Request $request): ?array
    {
        $orderId = (string) ($request->input('payload.merchantOrderId') ?? $request->input('merchantOrderId'));
        $payment = Payment::query()->where('gateway', 'phonepe')->where('gateway_order_id', $orderId)->first();

        return $payment === null ? null : [$payment, $this->confirm($payment)];
    }
}
