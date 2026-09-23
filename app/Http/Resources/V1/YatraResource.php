<?php

namespace App\Http\Resources\V1;

use App\Models\YatraStop;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Yatra */
class YatraResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,

            'status' => [
                'value' => $this->status?->value,
                'label' => $this->status?->getLabel(),
                'is_upcoming' => $this->status?->isUpcoming() ?? false,
            ],

            'starts_on' => $this->starts_on?->toDateString(),
            'ends_on' => $this->ends_on?->toDateString(),
            'day_count' => $this->dayCount(),
            'party_size' => $this->party_size,
            'is_public' => (bool) $this->is_public,

            'stops' => $this->whenLoaded('stops', fn () => $this->stops
                ->map(fn (YatraStop $stop): array => [
                    'id' => $stop->id,
                    'day_number' => $stop->day_number,
                    'sort_order' => $stop->sort_order,
                    'planned_on' => $stop->planned_on?->toDateString(),
                    'note' => $stop->note,
                    // The link back to the Passport: a stop is done when the
                    // visit it was planning for has been recorded.
                    'is_visited' => $stop->isVisited(),
                    'visit_id' => $stop->devotee_visit_id,
                    'temple' => $stop->temple === null ? null : [
                        'id' => $stop->temple->id,
                        'slug' => $stop->temple->slug,
                        'name' => $stop->temple->translate('name', app()->getLocale(), reviewedOnly: true),
                        'city' => $stop->temple->city,
                    ],
                ])
                ->values()),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
