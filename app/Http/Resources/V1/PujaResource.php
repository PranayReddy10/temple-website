<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\TemplePuja */
class PujaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'includes' => $this->includes,
            'eligibility' => $this->eligibility,
            'starts_at' => $this->starts_at ? substr((string) $this->starts_at, 0, 5) : null,
            'duration_minutes' => $this->duration_minutes,
            'duration_label' => $this->durationLabel(),
            'schedule_note' => $this->schedule_note,

            'fee' => [
                'is_free' => $this->is_free,
                // null here means the temple publishes no price. It does NOT
                // mean free, and a client must not render it as such.
                'amount' => $this->fee_amount !== null ? (float) $this->fee_amount : null,
                'currency' => $this->fee_currency,
                'label' => $this->feeLabel(),
            ],

            'booking' => [
                'url' => $this->booking_url,
                // The single field that decides whether a client may present
                // this as the temple's own booking route.
                'is_official' => $this->hasOfficialBooking(),
                'label' => $this->bookingLabel(),
                'note' => $this->booking_note,
            ],
        ];
    }
}
