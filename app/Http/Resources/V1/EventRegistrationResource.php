<?php

namespace App\Http\Resources\V1;

use App\Models\EventRegistration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A place at an event, as its devotee (and the gate) sees it. Shaped like a
 * seva booking, with `event` where a booking has `puja`.
 *
 * @mixin EventRegistration
 */
class EventRegistrationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'kind' => 'event',
            'reference' => $this->reference,
            // The gate code only once it holds.
            'code' => $this->isLive() ? $this->code : null,
            'qr_url' => $this->isLive() ? $this->qrUrl() : null,
            'status' => ['value' => $this->status->value, 'label' => $this->status->getLabel()],
            'is_live' => $this->isLive(),
            'event' => $this->event === null ? null : [
                'id' => $this->event->getKey(),
                'title' => $this->event->title,
                'type' => $this->event->type?->value,
                'group_name' => $this->event->group_name,
                'starts_at' => $this->event->starts_at ? substr((string) $this->event->starts_at, 0, 5) : null,
                'ends_at' => $this->event->ends_at ? substr((string) $this->event->ends_at, 0, 5) : null,
                'image_url' => $this->event->imageUrl(),
            ],
            'temple' => $this->temple === null ? null : [
                'id' => $this->temple->getKey(),
                'slug' => $this->temple->slug,
                'name' => $this->temple->name,
                'city' => $this->temple->city,
            ],
            'occurs_on' => $this->occurs_on?->toDateString(),
            // The same key as a seva booking, for screens that show both.
            'booked_for' => $this->occurs_on?->toDateString(),
            'people' => $this->people,
            'devotee_name' => $this->devotee_name,
            'devotee_phone' => $this->devotee_phone,
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
            'can_pay' => $this->canBePaidFor(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
