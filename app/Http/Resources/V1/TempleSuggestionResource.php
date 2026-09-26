<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A temple the devotee suggested, as they see it back: what they sent and
 * what the editors did with it.
 *
 * @mixin \App\Models\TempleSuggestion
 */
class TempleSuggestionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'city' => $this->city,
            'district' => $this->district,
            'state' => $this->whenLoaded('state', fn () => $this->state?->name),
            'submitter_role' => $this->submitter_role,
            'status' => [
                'value' => $this->status?->value,
                'label' => $this->status?->getLabel(),
            ],
            'review_note' => $this->review_note,
            // Where to go once it is listed, or which listing it already was.
            'temple' => $this->whenLoaded('temple', fn () => $this->temple && $this->temple->status?->isPublic() ? [
                'slug' => $this->temple->slug,
                'name' => $this->temple->name,
            ] : null),
            'photos' => $this->whenLoaded('photos', fn () => $this->photos->map->url()->filter()->values()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
