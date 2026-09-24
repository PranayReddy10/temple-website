<?php

namespace App\Support\Payments\Gateways;

use App\Models\Payment;
use App\Models\Setting;
use App\Support\Payments\PaymentGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Razorpay: Orders API plus Standard Checkout (checkout.js).
 *
 * A payment is paid when its signature — HMAC-SHA256 of "order_id|payment_id"
 * with the key secret — matches, or when Razorpay's API says the order is
 * paid. Webhooks are signed with a separate webhook secret.
 */
class Razorpay implements PaymentGateway
{
    public function code(): string
    {
        return 'razorpay';
    }

    protected function keyId(): string
    {
        return (string) setting('payments_razorpay_key_id');
    }

    protected function secret(): string
    {
        return (string) Setting::secret('payments_razorpay_key_secret');
    }

    public function start(Payment $payment): array
    {
        if ($payment->gateway_order_id === null) {
            $response = Http::withBasicAuth($this->keyId(), $this->secret())->timeout(15)
                ->post('https://api.razorpay.com/v1/orders', [
                    'amount' => $payment->amount_paise,
                    'currency' => $payment->currency,
                    'receipt' => substr($payment->uuid, 0, 40),
                    'notes' => ['payment' => $payment->uuid, 'plan' => $payment->plan?->code],
                ]);

            if (! $response->successful()) {
                throw new RuntimeException('Razorpay could not create the order: '.($response->json('error.description') ?? $response->status()));
            }

            $payment->forceFill(['gateway_order_id' => $response->json('id'), 'status' => Payment::PENDING])->save();
        }

        return [
            'view' => 'pay.razorpay',
            'data' => [
                'key' => $this->keyId(),
                'order_id' => $payment->gateway_order_id,
            ],
        ];
    }

    public function confirm(Payment $payment, ?Request $request = null): string
    {
        if ($request !== null && $request->filled('razorpay_signature')) {
            $expected = hash_hmac('sha256', $payment->gateway_order_id.'|'.$request->input('razorpay_payment_id'), $this->secret());

            if (hash_equals($expected, (string) $request->input('razorpay_signature'))) {
                $payment->gateway_payment_id = (string) $request->input('razorpay_payment_id');

                return Payment::PAID;
            }

            return Payment::FAILED;
        }

        // No signature (the devotee closed the window, or we are reconciling):
        // ask Razorpay what became of the order.
        $response = Http::withBasicAuth($this->keyId(), $this->secret())->timeout(15)
            ->get('https://api.razorpay.com/v1/orders/'.$payment->gateway_order_id.'/payments');

        if (! $response->successful()) {
            return Payment::PENDING;
        }

        $items = collect($response->json('items', []));
        $captured = $items->first(fn (array $p): bool => in_array($p['status'] ?? null, ['captured', 'authorized'], true));

        if ($captured !== null) {
            $payment->gateway_payment_id = $captured['id'];

            return Payment::PAID;
        }

        return $items->isNotEmpty() && $items->every(fn (array $p): bool => ($p['status'] ?? null) === 'failed')
            ? Payment::FAILED
            : Payment::PENDING;
    }

    public function webhook(Request $request): ?array
    {
        $secret = (string) Setting::secret('payments_razorpay_webhook_secret');
        $signature = (string) $request->header('X-Razorpay-Signature');

        if ($secret === '' || ! hash_equals(hash_hmac('sha256', $request->getContent(), $secret), $signature)) {
            return null;
        }

        $entity = $request->input('payload.payment.entity', []);
        $payment = Payment::query()->where('gateway', 'razorpay')->where('gateway_order_id', $entity['order_id'] ?? '')->first();

        if ($payment === null) {
            return null;
        }

        return match ($request->input('event')) {
            'payment.captured', 'order.paid' => [tap($payment, fn () => $payment->gateway_payment_id = $entity['id'] ?? null), Payment::PAID],
            'payment.failed' => [$payment, Payment::FAILED],
            default => null,
        };
    }
}
