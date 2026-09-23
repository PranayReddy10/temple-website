<?php

namespace App\Support\Payments\Gateways;

use App\Models\Payment;
use App\Models\Setting;
use App\Support\Payments\PaymentGateway;
use Illuminate\Http\Request;

/**
 * PayU hosted checkout: a form posted to PayU with a SHA-512 hash, and PayU
 * posting back with a reverse hash that proves the result came from PayU.
 */
class PayU implements PaymentGateway
{
    public function code(): string
    {
        return 'payu';
    }

    protected function key(): string
    {
        return (string) setting('payments_payu_key');
    }

    protected function salt(): string
    {
        return (string) Setting::secret('payments_payu_salt');
    }

    protected function txnId(Payment $payment): string
    {
        return 'tp'.substr(str_replace('-', '', $payment->uuid), 0, 23);
    }

    public function start(Payment $payment): array
    {
        $devotee = $payment->devotee;
        $fields = [
            'key' => $this->key(),
            'txnid' => $this->txnId($payment),
            'amount' => number_format($payment->amount_paise / 100, 2, '.', ''),
            'productinfo' => $payment->plan?->code ?? 'subscription',
            'firstname' => preg_replace('/[^A-Za-z ]/', '', $devotee->name) ?: 'Devotee',
            'email' => $devotee->email ?? 'devotee'.$devotee->getKey().'@example.com',
            'phone' => preg_replace('/\D/', '', (string) $devotee->phone) ?: '9999999999',
            'surl' => route('pay.return', ['payment' => $payment, 'gateway' => 'payu']),
            'furl' => route('pay.return', ['payment' => $payment, 'gateway' => 'payu']),
        ];
        $fields['hash'] = hash('sha512', implode('|', [
            $fields['key'], $fields['txnid'], $fields['amount'], $fields['productinfo'], $fields['firstname'], $fields['email'],
            '', '', '', '', '', '', '', '', '', '', $this->salt(),
        ]));

        $payment->forceFill(['gateway_order_id' => $fields['txnid'], 'status' => Payment::PENDING])->save();

        return [
            'view' => 'pay.form',
            'data' => [
                'action' => setting('payments_payu_env') === 'production' ? 'https://secure.payu.in/_payment' : 'https://test.payu.in/_payment',
                'fields' => $fields,
            ],
        ];
    }

    public function confirm(Payment $payment, ?Request $request = null): string
    {
        if ($request === null || ! $request->filled('hash')) {
            return Payment::PENDING;
        }

        $r = $request;
        $reverse = hash('sha512', implode('|', [
            $this->salt(), $r->input('status'), '', '', '', '', '',
            $r->input('udf5'), $r->input('udf4'), $r->input('udf3'), $r->input('udf2'), $r->input('udf1'),
            $r->input('email'), $r->input('firstname'), $r->input('productinfo'), $r->input('amount'), $r->input('txnid'), $this->key(),
        ]));

        $genuine = hash_equals($reverse, (string) $r->input('hash'))
            && $r->input('txnid') === $payment->gateway_order_id
            && (int) round(((float) $r->input('amount')) * 100) === $payment->amount_paise;

        if (! $genuine) {
            return Payment::FAILED;
        }

        if ($r->input('status') === 'success') {
            $payment->gateway_payment_id = (string) $r->input('mihpayid');

            return Payment::PAID;
        }

        return Payment::FAILED;
    }

    public function webhook(Request $request): ?array
    {
        $payment = Payment::query()->where('gateway', 'payu')->where('gateway_order_id', (string) $request->input('txnid'))->first();

        return $payment === null ? null : [$payment, $this->confirm($payment, $request)];
    }
}
