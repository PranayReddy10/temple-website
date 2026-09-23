<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Temple */
class TempleDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->localised('name'),
            'alternate_names' => $this->whenLoaded('aliases', fn () => $this->aliases
                ->map(fn ($alias) => ['name' => $alias->name, 'locale' => $alias->locale])
                ->values()),

            'deity' => $this->whenLoaded('deity', fn () => $this->deity ? [
                'slug' => $this->deity->slug,
                'name' => $this->deity->name,
                'alternate_names' => $this->deity->alternate_names,
            ] : null),

            'categories' => $this->whenLoaded('categories', fn () => $this->categories
                ->map(fn ($c) => ['slug' => $c->slug, 'name' => $c->name, 'kind' => $c->kind])
                ->values()),

            'location' => [
                'address' => $this->address,
                'city' => $this->city,
                'district' => $this->whenLoaded('district', fn () => $this->district?->name),
                'state' => $this->whenLoaded('state', fn () => $this->state?->name),
                'pincode' => $this->pincode,
                'latitude' => $this->latitude !== null ? (float) $this->latitude : null,
                'longitude' => $this->longitude !== null ? (float) $this->longitude : null,
            ],

            /*
             * The verse for this place: its own where it has one, its
             * deity's otherwise. Never blank where the deity has one, so the
             * app does not render a heading with nothing under it.
             */
            'mantra' => [
                'text' => $this->mantraText(),
                'transliteration' => $this->localised('mantra_transliteration')
                    ?: $this->mantraTransliteration(),
                'is_temple_specific' => filled($this->mantra),
            ],

            // This temple's songs first, then its deity's.
            'devotional_media' => DevotionalMediaResource::collection($this->allMedia()),

            'about' => [
                'short_description' => $this->localised('short_description'),
                'history' => $this->localised('history'),
                'significance' => $this->localised('significance'),
                'architecture_style' => $this->architecture_style,
                'built_period' => $this->built_period,
            ],

            /*
             * Translated first among all of these: a dress code or an entry
             * rule that a devotee cannot read is the one field where not
             * understanding it means being turned away at the gate.
             */
            'visitor_rules' => [
                'dress_code' => $this->localised('dress_code'),
                'photography' => $this->localised('photography_policy'),
                'mobile' => $this->mobile_policy,
                'footwear' => $this->footwear_policy,
                'entry' => $this->localised('entry_rules'),
                'queue' => $this->localised('queue_information'),
            ],

            'contact' => [
                'website' => $this->official_website,
                'phone' => $this->contact_phone,
                'email' => $this->contact_email,
            ],

            /*
             * Provenance travels with the record. A client is expected to show
             * this: section 20 of the plan requires official, verified and
             * community information to stay visibly distinct, and that is only
             * possible if the level reaches the device.
             */
            // An editorial "famous temple" mark. Not a trust claim: read
            // `trust` for how far the record can be relied on.
            'is_featured' => (bool) $this->is_featured,

            'trust' => [
                'level' => $this->verification_status?->value,
                'label' => $this->verification_status?->getLabel(),
                'source_name' => $this->source_name,
                'source_url' => $this->source_url,
                'last_verified_at' => $this->last_verified_at?->toDateString(),
                'is_stale' => $this->isStale(),
            ],

            'timings' => TimingResource::collection($this->whenLoaded('timings')),
            'pujas' => PujaResource::collection($this->whenLoaded('pujas')),
            'photos' => PhotoResource::collection($this->whenLoaded('photos')),
            'closures' => ClosureResource::collection($this->whenLoaded('closures')),
            'events' => EventResource::collection($this->whenLoaded('events')),

            'facilities' => $this->whenLoaded('facilities', fn () => $this->facilities
                ->map(fn ($f) => [
                    'slug' => $f->slug,
                    'name' => $f->name,
                    'group' => $f->group,
                    'is_verified' => (bool) $f->pivot->is_verified,
                    'note' => $f->pivot->note,
                ])
                ->values()),

            // Computed so a client never has to reimplement closure logic and
            // get it subtly wrong for someone standing at the gate.
            'is_closed_today' => $this->whenLoaded('closures', fn (): bool => $this->isClosedOn()),

            'published_at' => $this->published_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            'language' => app()->getLocale(),
        ];
    }

    /** Only reviewed translations reach devotees; see TempleSummaryResource. */
    protected function localised(string $field): mixed
    {
        return $this->resource->translate($field, app()->getLocale(), reviewedOnly: true);
    }
}
