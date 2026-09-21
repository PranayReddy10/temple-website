<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\TempleClosure */
class ClosureResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reason' => $this->reason,
            'starts_on' => $this->starts_on?->toDateString(),
            'ends_on' => $this->lastDay()->toDateString(),
            'is_full_day' => $this->is_full_day,
            'opens_at' => $this->opens_at ? substr((string) $this->opens_at, 0, 5) : null,
            'closes_at' => $this->closes_at ? substr((string) $this->closes_at, 0, 5) : null,
            'is_active_today' => $this->coversDate(now()),
            'notes' => $this->notes,
        ];
    }
}
