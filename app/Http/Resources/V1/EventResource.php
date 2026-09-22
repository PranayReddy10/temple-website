<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\TempleEvent */
class EventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type?->value,
            'title' => $this->title,
            'description' => $this->description,
            'image_url' => $this->imageUrl(),

            'starts_on' => $this->starts_on?->toDateString(),
            'ends_on' => $this->lastDay()->toDateString(),
            'date_label' => $this->dateLabel(),
            'is_all_day' => $this->is_all_day,
            'starts_at' => $this->starts_at ? substr((string) $this->starts_at, 0, 5) : null,
            'ends_at' => $this->ends_at ? substr((string) $this->ends_at, 0, 5) : null,
            'is_happening_today' => $this->coversDate(now()),

            'temple' => $this->whenLoaded('temple', fn () => [
                'slug' => $this->temple->slug,
                'name' => $this->temple->name,
                'city' => $this->temple->city,
            ]),
        ];
    }
}
