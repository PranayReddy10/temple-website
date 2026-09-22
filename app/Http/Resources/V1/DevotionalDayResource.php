<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\DevotionalDay */
class DevotionalDayResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'weekday' => $this->weekday,
            'weekday_name' => $this->weekdayName(),
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'significance' => $this->significance,

            'mantra' => $this->mantra,
            'mantra_transliteration' => $this->mantra_transliteration,

            // The colour the app themes itself with for this day.
            'accent_color' => $this->accentColor(),

            'deity' => $this->whenLoaded('deity', fn () => [
                'slug' => $this->deity->slug,
                'name' => $this->deity->name,
                'alternate_names' => $this->deity->alternate_names,
            ]),

            'media' => DevotionalMediaResource::collection($this->whenLoaded('media')),

            // Temples of this day's deity, so Monday can open on Shiva temples.
            'temples' => TempleSummaryResource::collection($this->whenLoaded('dayTemples')),
        ];
    }
}
