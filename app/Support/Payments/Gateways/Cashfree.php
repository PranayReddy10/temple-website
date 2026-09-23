<?php

namespace App\Support\Payments\Gateways;

use App\Models\Payment;
use App\Models\Setting;
use App\Support\Payments\PaymentGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Cashfree Payments (PG API 2023-08-01): an order with a payment session,
 * Cashfree's JS checkout, and the order's own status as the answer.
 */
class Cashfree implements PaymentGateway
{
    public function code(): string
    {
        return 'cashfree';
    }

    protected function production(): bool
    {
        return setting('payments_cashfree_env') === 'production';
    }

    protected function http()
    {
        return Http::withHeaders([
            'x-client-id' => (string) setting('payments_cashfree_app_id'),
            'x-client-secret' => (string) Setting::secret('payments_cashfree_secret_key'),
            'x-api-version' => '2023-08-01',
        ])->baseUrl($this->production() ? 'https://api.cashfree.com/pg' : 'https://sandbox.cashfree.com/pg')->timeout(15);
    }

    protected function orderId(Payment $payment): string
    {
        return 'tp_'.str_replace('-', '', $payment->uuid);
    }

    public function start(Payment $payment): array
    {
        $devotee = $payment->devotee;
        $response = $this->http()->post('/orders', [
            'order_id' => $this->orderId($payment),
            'order_amount' => round($payment->amount_paise / 100, 2),
            'order_currency' => $payment->currency,
            'customer_details' => array_filter([
                'customer_id' => 'devotee_'.$devotee->getKey(),
                'customer_name' => $devotee->name,
                'customer_email' => $devotee->email,
                // Required by Cashfree; the devotee can change it on its page.
                'customer_phone' => preg_replace('/\D/', '', (string) $devotee->phone) ?: '9999999999',
            ]),
            'order_meta' => ['return_url' => route('pay.return', ['payment' => $payment, 'gateway' => 'cashfree'])],
            'order_note' => $payment->plan?->name,
        ]);

        if (! $response->successful() || blank($response->json('payment_session_id'))) {
            throw new RuntimeException('Cashfree could not create the order: '.($response->json('message') ?? $response->status()));
        }

        $payment->forceFill(['gateway_order_id' => $this->orderId($payment), 'status' => Payment::PENDING])->save();

        return [
            'view' => 'pay.cashfree',
            'data' => [
                'session_id' => $response->json('payment_session_id'),
                'mode' => $this->production() ? 'production' : 'sandbox',
            ],
        ];
    }

    public function confirm(Payment $payment, ?Request $request = null): string
    {
        $response = $this->http()->get('/orders/'.$this->orderId($payment));

        if (! $response->successful()) {
            return Payment::PENDING;
        }

        return match ($response->json('order_status')) {
            'PAID' => Payment::PAID,
            'EXPIRED', 'TERMINATED' => Payment::FAILED,
            default => Payment::PENDING,
        };
    }

    public function webhook(Request $request): ?array
    {
        $payment = Payment::query()->where('gateway', 'cashfree')->where('gateway_order_id', (string) $request->input('data.order.order_id'))->first();

        return $payment === null ? null : [$payment, $this->confirm($payment)];
    }
}
