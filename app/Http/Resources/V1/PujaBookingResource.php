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
            'code' => $this->code,
            'qr_url' => $this->qrUrl(),
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
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
