<?php

namespace App\Support\Payments;

use App\Models\Payment;
use Illuminate\Http\Request;

/**
 * One Indian payment gateway.
 *
 * start() creates the order at the gateway and says how to check out: a page
 * of ours that opens the gateway's own checkout, or a redirect to it.
 * confirm() asks the gateway, server to server, how the payment ended — the
 * browser coming back is only a hint that it is worth asking. webhook()
 * handles the gateway's own notification the same way.
 */
interface PaymentGateway
{
    public function code(): string;

    /**
     * @return array{redirect?: string, view?: string, data?: array<string, mixed>}
     */
    public function start(Payment $payment): array;

    /** One of Payment::PAID, Payment::FAILED, Payment::PENDING. */
    public function confirm(Payment $payment, ?Request $request = null): string;

    /**
     * The payment a webhook concerns and the status the gateway confirms,
     * or null when it is not for us or cannot be verified.
     *
     * @return array{0: Payment, 1: string}|null
     */
    public function webhook(Request $request): ?array;
}
