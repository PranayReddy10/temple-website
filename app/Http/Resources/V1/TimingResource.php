<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\TempleTiming */
class TimingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind?->value,
            'label' => $this->label,
            // null means "every day"; clients should not assume Sunday.
            'day_of_week' => $this->day_of_week,
            'day_label' => $this->dayLabel(),
            'opens_at' => $this->opens_at ? substr((string) $this->opens_at, 0, 5) : null,
            'closes_at' => $this->closes_at ? substr((string) $this->closes_at, 0, 5) : null,
            'window' => $this->window(),
            'notes' => $this->notes,
        ];
    }
}
