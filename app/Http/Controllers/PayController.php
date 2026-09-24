<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Support\Payments\Payments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

/**
 * The checkout pages the app opens in its in-app browser.
 *
 * /pay/{payment} is signed and short-lived: it is the only link that can start
 * a checkout, and it names no amount — the amount was fixed from the plan when
 * the payment was created. The gateway sends the devotee back to /return,
 * which asks the gateway how it went and lands on /done, where the app's
 * browser closes itself.
 */
class PayController extends Controller
{
    public function __construct(protected Payments $payments) {}

    public function show(Payment $payment): View|RedirectResponse
    {
        if ($payment->isSettled()) {
            return redirect()->route('pay.done', $payment);
        }

        try {
            $checkout = $this->payments->gateway($payment->gateway)->start($payment->load('plan', 'devotee'));
        } catch (Throwable $e) {
            Log::warning('Checkout could not start', ['payment' => $payment->uuid, 'error' => $e->getMessage()]);

            return view('pay.done', ['payment' => $payment, 'error' => 'The payment could not be started. Please go back and try again, or choose another method.']);
        }

        if (isset($checkout['redirect'])) {
            return redirect()->away($checkout['redirect']);
        }

        return view($checkout['view'], ['payment' => $payment, 'devotee' => $payment->devotee] + ($checkout['data'] ?? []));
    }

    public function return(Request $request, Payment $payment, string $gateway): RedirectResponse
    {
        abort_unless($gateway === $payment->gateway, 404);

        if ($request->boolean('cancelled')) {
            $this->payments->apply($payment, Payment::FAILED, reason: 'Cancelled.');

            return redirect()->route('pay.done', $payment);
        }

        try {
            $this->payments->reconcile($payment, $request);
        } catch (Throwable $e) {
            Log::warning('Payment return could not be confirmed', ['payment' => $payment->uuid, 'error' => $e->getMessage()]);
        }

        return redirect()->route('pay.done', $payment);
    }

    public function done(Payment $payment): View
    {
        return view('pay.done', ['payment' => $payment->fresh('plan'), 'error' => null]);
    }
}
