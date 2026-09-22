<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\DevoteeVisit */
class VisitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'temple' => $this->whenLoaded('temple', fn () => [
                'id' => $this->temple->id,
                'slug' => $this->temple->slug,
                'name' => $this->temple->translate('name', app()->getLocale(), reviewedOnly: true),
                'city' => $this->temple->city,
            ]),

            'visited_on' => $this->visited_on?->toDateString(),
            'visited_at' => $this->visited_at,

            'method' => [
                'value' => $this->method?->value,
                'label' => $this->method?->getLabel(),
            ],

            /*
             * Whether this counts as a stamp, and why.
             *
             * The app shows the two differently, so the reason has to reach
             * the device: "you were there" and "you told us you were there"
             * are the same row with different standing.
             */
            'is_verified' => (bool) $this->is_verified,
            'distance_metres' => $this->distance_metres,

            'note' => $this->note,
            'is_public' => (bool) $this->is_public,

            'photos' => VisitPhotoResource::collection($this->whenLoaded('photos')),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
