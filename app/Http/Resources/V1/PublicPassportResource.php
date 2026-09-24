<?php

namespace App\Http\Resources\V1;

use App\Models\DevoteeVisit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A passport as someone else sees it after scanning its code.
 *
 * Name, photo and the stamps — never an email, a phone number, a date of
 * birth or a note. Only visits the devotee left public are listed, and the
 * counts are taken from those same visits, so a private pilgrimage does not
 * show up as an unexplained extra stamp.
 *
 * @mixin \App\Models\Devotee
 */
class PublicPassportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $visits = $this->resource->visits()
            ->public()
            ->with(['temple:id,slug,name,city,state_id', 'temple.state:id,name'])
            ->limit(500)
            ->get();

        $verified = $visits->where('is_verified', true);

        return [
            'name' => $this->name,
            'avatar_url' => $this->avatarUrl(),
            'home_state' => $this->homeState?->name,
            'joined_at' => $this->created_at?->toIso8601String(),

            'stamps' => $verified->pluck('temple_id')->unique()->count(),
            'temples_visited' => $visits->pluck('temple_id')->unique()->count(),
            'visits_recorded' => $visits->count(),
            'states_covered' => $visits->map(fn (DevoteeVisit $v) => $v->temple?->state_id)->filter()->unique()->count(),

            'visits' => $visits->map(fn (DevoteeVisit $v): array => [
                'id' => $v->id,
                'temple' => $v->temple === null ? null : [
                    'id' => $v->temple->id,
                    'slug' => $v->temple->slug,
                    'name' => $v->temple->translate('name', app()->getLocale(), reviewedOnly: true),
                    'city' => $v->temple->city,
                    'state' => $v->temple->state?->name,
                ],
                'visited_on' => $v->visited_on?->toDateString(),
                'method' => [
                    'value' => $v->method?->value,
                    'label' => $v->method?->getLabel(),
                ],
                'is_verified' => (bool) $v->is_verified,
            ])->values()->all(),
        ];
    }
}
