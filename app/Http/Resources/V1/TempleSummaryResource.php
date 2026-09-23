<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The list representation: enough to render a search result card without a
 * second request, and nothing more. Keeping this lean matters because the app
 * is used on patchy mobile networks at temple sites.
 *
 * @mixin \App\Models\Temple
 */
class TempleSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            // Served in the request's language where a reviewed translation
            // exists, and in English where it does not — a half-translated
            // temple must still render as a usable card.
            'name' => $this->localised('name'),
            'short_description' => $this->localised('short_description'),

            'deity' => $this->whenLoaded('deity', fn () => [
                'slug' => $this->deity->slug,
                'name' => $this->deity->name,
            ]),

            'location' => [
                'city' => $this->city,
                'district' => $this->whenLoaded('district', fn () => $this->district?->name),
                'state' => $this->whenLoaded('state', fn () => $this->state?->name),
                'latitude' => $this->latitude !== null ? (float) $this->latitude : null,
                'longitude' => $this->longitude !== null ? (float) $this->longitude : null,
            ],

            // Present only on a nearby search, rounded to 10m — more precision
            // would imply an accuracy the source coordinates do not have.
            'distance_km' => $this->when(
                isset($this->distance_km),
                fn () => round((float) $this->distance_km, 2),
            ),

            // An editorial "famous temple" mark. Not a trust claim: read
            // `trust` for how far the record can be relied on.
            'is_featured' => (bool) $this->is_featured,

            'trust' => [
                'level' => $this->verification_status?->value,
                'label' => $this->verification_status?->getLabel(),
                'last_verified_at' => $this->last_verified_at?->toDateString(),
            ],

            'primary_photo' => $this->whenLoaded(
                'primaryPhoto',
                fn () => $this->primaryPhoto ? new PhotoResource($this->primaryPhoto) : null,
            ),

            // What the client actually got, which is not always what it asked
            // for. A client that cannot tell has no way to decide whether to
            // render its own fallback.
            'language' => app()->getLocale(),
        ];
    }

    /**
     * Only reviewed translations reach devotees.
     *
     * An unreviewed row may be a bulk import or a machine translation, and a
     * deity's name rendered wrongly in someone's own language is worse than
     * the English they can at least recognise.
     */
    protected function localised(string $field): mixed
    {
        return $this->resource->translate($field, app()->getLocale(), reviewedOnly: true);
    }
}
