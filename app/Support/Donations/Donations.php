<?php

namespace App\Support\Donations;

use App\Enums\TempleStatus;
use App\Models\Devotee;
use App\Models\Payment;
use App\Models\Temple;
use App\Models\TempleDonation;
use App\Support\AppConfig;
use App\Support\DevotionalClock;
use App\Support\Payments\Payments;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Giving to a temple's hundi online. The amount is the devotee's to choose,
 * within limits, and the gift counts once the gateway confirms the money,
 * never on the app's word.
 */
final class Donations
{
    public function __construct(protected Payments $payments) {}

    /**
     * @param  array{amount_paise: int, purpose?: ?string, donor_name?: ?string, is_anonymous?: ?bool, note?: ?string}  $data
     */
    public function give(Devotee $devotee, Temple $temple, array $data, ?string $gateway = null): TempleDonation
    {
        // Money only reaches a temple whose owner and bank are approved.
        if ($temple->status !== TempleStatus::Published || ! $temple->accepts_donations || ! $temple->canCollectPayments()) {
            throw ValidationException::withMessages(['temple' => 'This temple does not take online hundi donations yet.']);
        }

        $amount = (int) $data['amount_paise'];
        if ($amount < TempleDonation::MIN_PAISE || $amount > TempleDonation::MAX_PAISE) {
            throw ValidationException::withMessages(['amount' => 'Give between ₹10 and ₹5,00,000 at a time.']);
        }

        $gateway ??= AppConfig::payments('android')['default_gateway'] ?? null;
        if ($gateway === null || ! in_array($gateway, AppConfig::enabledGateways(), true)) {
            throw ValidationException::withMessages(['gateway' => 'Online payments are not open yet. Please give at the temple for now.']);
        }

        return DB::transaction(function () use ($devotee, $temple, $data, $amount, $gateway): TempleDonation {
            $donation = TempleDonation::create([
                'temple_id' => $temple->getKey(),
                'devotee_id' => $devotee->getKey(),
                'amount_paise' => $amount,
                'purpose' => array_key_exists($data['purpose'] ?? '', TempleDonation::PURPOSES) ? $data['purpose'] : 'general',
                'donor_name' => filled($data['donor_name'] ?? null) ? $data['donor_name'] : $devotee->name,
                'is_anonymous' => (bool) ($data['is_anonymous'] ?? false),
                'note' => $data['note'] ?? null,
            ]);

            $payment = $this->payments->beginFor($devotee, $amount, Payment::DONATION, $gateway, ['donation' => $donation->reference]);
            $donation->payment()->associate($payment);
            $donation->save();

            return $donation->load(['temple', 'payment']);
        });
    }

    public function paid(TempleDonation $donation): TempleDonation
    {
        if ($donation->status !== TempleDonation::PAID) {
            $donation->forceFill([
                'status' => TempleDonation::PAID,
                'paid_at' => now(),
                'paid_on' => DevotionalClock::now()->toDateString(),
            ])->save();
        }

        return $donation;
    }

    public function failed(TempleDonation $donation): TempleDonation
    {
        if ($donation->status === TempleDonation::PENDING) {
            $donation->forceFill(['status' => TempleDonation::FAILED])->save();
        }

        return $donation;
    }

    public function refunded(TempleDonation $donation): TempleDonation
    {
        $donation->forceFill(['status' => TempleDonation::REFUNDED])->save();

        return $donation;
    }
}
