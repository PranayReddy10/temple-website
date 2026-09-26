<?php

namespace App\Support\Payments;

use App\Models\Devotee;
use App\Models\DevoteeSubscription;
use App\Models\Payment;
use App\Models\SubscriptionPlan;
use App\Support\AppConfig;
use App\Support\Payments\Gateways\Cashfree;
use App\Support\Payments\Gateways\PayU;
use App\Support\Payments\Gateways\PhonePe;
use App\Support\Payments\Gateways\Razorpay;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Starting a payment, and acting on how it ended.
 *
 * apply() is the one place a payment becomes paid and a subscription starts.
 * It locks the row, so the browser coming back and the gateway's webhook
 * arriving at the same moment cannot switch a plan on twice; the unique
 * payment_id on subscriptions backs that up in the schema.
 */
class Payments
{
    public function gateway(string $code): PaymentGateway
    {
        return match ($code) {
            'razorpay' => app(Razorpay::class),
            'phonepe' => app(PhonePe::class),
            'cashfree' => app(Cashfree::class),
            'payu' => app(PayU::class),
            default => throw new InvalidArgumentException("Unknown gateway {$code}."),
        };
    }

    public function begin(Devotee $devotee, SubscriptionPlan $plan, string $gateway): Payment
    {
        // Priced from the plan here, never from anything the app sent.
        $payment = $this->beginFor($devotee, $plan->price_paise, Payment::SUBSCRIPTION, $gateway, currency: $plan->currency);
        $payment->forceFill(['subscription_plan_id' => $plan->getKey()])->save();

        return $payment;
    }

    /**
     * A payment for anything: a plan, a seva booking. The amount is always
     * decided on the server by whatever is being paid for.
     *
     * @param  array<string, mixed>  $meta
     */
    public function beginFor(Devotee $devotee, int $amountPaise, string $purpose, string $gateway, array $meta = [], string $currency = 'INR'): Payment
    {
        if (! in_array($gateway, AppConfig::enabledGateways(), true)) {
            throw new InvalidArgumentException('That payment method is not available.');
        }

        return Payment::create([
            'devotee_id' => $devotee->getKey(),
            'purpose' => $purpose,
            'gateway' => $gateway,
            'amount_paise' => $amountPaise,
            'currency' => $currency,
            'meta' => $meta === [] ? null : $meta,
        ]);
    }

    /** Asks the gateway how this payment ended and records it. */
    public function reconcile(Payment $payment, ?\Illuminate\Http\Request $request = null): Payment
    {
        if ($payment->isSettled() || $payment->gateway_order_id === null) {
            return $payment;
        }

        return $this->apply($payment, $this->gateway($payment->gateway)->confirm($payment, $request), $payment->gateway_payment_id);
    }

    public function apply(Payment $payment, string $status, ?string $gatewayPaymentId = null, ?string $reason = null): Payment
    {
        return DB::transaction(function () use ($payment, $status, $gatewayPaymentId, $reason): Payment {
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->getKey());

            // A paid payment stays paid: a late "failed" webhook for an
            // earlier attempt must not undo it.
            if ($locked->isPaid() || $status === Payment::PENDING) {
                return $locked;
            }

            if ($status === Payment::FAILED) {
                if ($locked->status !== Payment::FAILED) {
                    $locked->forceFill(['status' => Payment::FAILED, 'failure_reason' => $reason ?? 'Payment was not completed.'])->save();
                    // A booking waiting on this money is off; the slot goes
                    // back to whoever books next.
                    if ($locked->booking !== null) {
                        app(\App\Support\Bookings\PujaBookings::class)->paymentFailed($locked->booking, $locked->failure_reason);
                    }
                }

                return $locked;
            }

            $locked->forceFill([
                'status' => Payment::PAID,
                'paid_at' => now(),
                'gateway_payment_id' => $gatewayPaymentId ?? $locked->gateway_payment_id,
                'failure_reason' => null,
            ])->save();

            if ($locked->plan !== null) {
                $this->startSubscription($locked->devotee, $locked->plan, $locked);
            }

            // The one place a booking becomes confirmed: the gateway said
            // the money arrived, not the app.
            if ($locked->booking !== null) {
                app(\App\Support\Bookings\PujaBookings::class)->confirm($locked->booking);
            }

            return $locked;
        });
    }

    /**
     * Starts a plan, after any the devotee already holds: renewing a month
     * early adds a month rather than wasting the rest of the current one.
     */
    public function startSubscription(Devotee $devotee, SubscriptionPlan $plan, ?Payment $payment = null, ?int $days = null, ?int $grantedBy = null, ?string $note = null): DevoteeSubscription
    {
        $latestEnd = $devotee->subscriptions()->whereNull('cancelled_at')->max('ends_at');
        $starts = $latestEnd !== null && Carbon::parse($latestEnd)->isFuture() ? Carbon::parse($latestEnd) : now();

        return DevoteeSubscription::create([
            'devotee_id' => $devotee->getKey(),
            'subscription_plan_id' => $plan->getKey(),
            'payment_id' => $payment?->getKey(),
            'starts_at' => $starts,
            'ends_at' => $starts->copy()->addDays($days ?? $plan->duration_days),
            'granted_by' => $grantedBy,
            'note' => $note,
        ]);
    }

    /** Marks a payment refunded (after refunding it in the gateway's dashboard) and ends its plan. */
    public function refunded(Payment $payment): void
    {
        DB::transaction(function () use ($payment): void {
            $payment->forceFill(['status' => Payment::REFUNDED])->save();
            $payment->subscription?->forceFill(['cancelled_at' => now()])->save();

            if ($payment->booking !== null) {
                app(\App\Support\Bookings\PujaBookings::class)->refunded($payment->booking);
            }
        });
    }
}
