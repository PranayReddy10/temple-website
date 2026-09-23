<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Support\Payments\Payments;
use Illuminate\Console\Command;
use Throwable;

/**
 * Asks the gateway once more about payments left open for over an hour, and
 * closes the ones abandoned for a day. Nothing is lost by asking: a payment
 * that did go through is switched on here even if its webhook never came.
 */
class ExpireStalePayments extends Command
{
    protected $signature = 'payments:expire-stale';

    protected $description = 'Reconcile open payments with their gateway and close abandoned ones';

    public function handle(Payments $payments): int
    {
        Payment::query()
            ->whereIn('status', [Payment::CREATED, Payment::PENDING])
            ->where('created_at', '<', now()->subHour())
            ->each(function (Payment $payment) use ($payments): void {
                try {
                    $payment = $payments->reconcile($payment);
                } catch (Throwable) {
                    // The gateway is unreachable; try again next hour.
                }

                if (! $payment->isSettled() && $payment->created_at->lt(now()->subDay())) {
                    $payments->apply($payment, Payment::FAILED, reason: 'Abandoned before paying.');
                }
            });

        return self::SUCCESS;
    }
}
