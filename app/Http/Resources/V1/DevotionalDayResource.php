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

            // The day's own where it has one, the deity's otherwise. A day
            // carries its own only where a tradition differs.
            'mantra' => $this->mantraText(),
            'mantra_transliteration' => $this->mantraTransliteration(),

            // The colour the app themes itself with for this day.
            'accent_color' => $this->accentColor(),

            'deity' => $this->whenLoaded('deity', fn () => $this->deity === null ? null : [
                'slug' => $this->deity->slug,
                'name' => $this->deity->name,
                'alternate_names' => $this->deity->alternate_names,
                'image_url' => $this->deity->imageUrl(),
                'mantra' => $this->deity->mantra,
                'mantra_transliteration' => $this->deity->mantra_transliteration,
                'mantra_meaning' => $this->deity->mantra_meaning,
            ]),

            // The day's media and the deity's together, the day's first
            // because it is the more specific of the two.
            'media' => DevotionalMediaResource::collection($this->allMedia()),

            // Temples of this day's deity, so Monday can open on Shiva temples.
            'temples' => TempleSummaryResource::collection($this->whenLoaded('dayTemples')),
        ];
    }
}
