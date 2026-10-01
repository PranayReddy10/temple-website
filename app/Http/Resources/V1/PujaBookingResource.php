<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A seva booking as the devotee who made it sees it: what, where, when, for
 * whom, what it cost, and the code the counter scans.
 *
 * @mixin \App\Models\PujaBooking
 */
class PujaBookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $puja = $this->whenLoaded('puja', fn () => $this->puja);
        $temple = $this->whenLoaded('temple', fn () => $this->temple);

        return [
            // The reference is the public id; the row id never leaves.
            'reference' => $this->reference,
            // The counter code only once the booking holds: a booking still
            // waiting on its money must not look like a ticket.
            'code' => $this->isLive() ? $this->code : null,
            'qr_url' => $this->isLive() ? $this->qrUrl() : null,
            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->getLabel(),
            ],
            'is_live' => $this->isLive(),
            'temple' => $this->temple === null ? null : [
                'id' => $this->temple->getKey(),
                'slug' => $this->temple->slug,
                'name' => $this->temple->name,
                'city' => $this->temple->city,
                'cover' => $this->temple->coverUrls(),
            ],
            'puja' => $this->puja === null ? null : [
                'id' => $this->puja->getKey(),
                'name' => $this->puja->name,
                'kind' => $this->puja->kind?->value,
                'starts_at' => $this->puja->starts_at ? substr((string) $this->puja->starts_at, 0, 5) : null,
                'image_url' => $this->puja->imageUrl(),
                'instructions' => $this->puja->booking_instructions,
            ],
            'booked_for' => $this->booked_for?->toDateString(),
            // The time slot, like a show time; null for a seva without slots.
            'slot' => $this->slot_starts_at ? [
                'starts_at' => substr((string) $this->slot_starts_at, 0, 5),
                'ends_at' => $this->slot_ends_at ? substr((string) $this->slot_ends_at, 0, 5) : null,
                'label' => $this->slotLabel(),
            ] : null,
            'expired_at' => $this->expired_at?->toIso8601String(),
            'people' => $this->people,
            'devotee_name' => $this->devotee_name,
            'devotee_phone' => $this->devotee_phone,
            'gotram' => $this->gotram,
            'nakshatram' => $this->nakshatram,
            'note' => $this->note,
            'amount_paise' => $this->amount_paise,
            'amount' => $this->amountLabel(),
            'is_free' => $this->isFree(),
            'payment' => $this->payment === null ? null : [
                'id' => $this->payment->uuid,
                'status' => $this->payment->status,
                'gateway' => $this->payment->gateway,
                'paid_at' => $this->payment->paid_at?->toIso8601String(),
            ],
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'verified_at' => $this->verified_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancel_reason' => $this->cancel_reason,
            'can_cancel' => $this->canBeCancelledByDevotee(),
            // Awaiting payment and not yet past: the app offers "Pay now".
            'can_pay' => $this->canBePaidFor(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
