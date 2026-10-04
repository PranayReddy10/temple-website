<?php

namespace App\Http\Resources\V1;

use App\Models\TempleTiming;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TempleTiming */
class TimingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind?->value,
            'label' => $this->label,
            // The days it holds on (0 = Sunday … 6 = Saturday); null is
            // every day. A timing with days replaces the every-day timing of
            // the same kind on those days.
            'days' => $this->days,
            // The first of `days`, for app versions that read only this.
            'day_of_week' => $this->day_of_week,
            'day_label' => $this->dayLabel(),
            'opens_at' => $this->opens_at ? substr((string) $this->opens_at, 0, 5) : null,
            'closes_at' => $this->closes_at ? substr((string) $this->closes_at, 0, 5) : null,
            'window' => $this->window(),
            'notes' => $this->notes,
        ];
    }
}
