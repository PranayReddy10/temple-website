<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Only ever returned to the devotee who wrote it, so the body is included in
 * full. Nothing else in the API serves a memory.
 *
 * @mixin \App\Models\DevoteeMemory
 */
class MemoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
            'happened_on' => $this->happened_on?->toDateString(),
            'is_private' => (bool) $this->is_private,

            'temple' => $this->whenLoaded('temple', fn () => $this->temple ? [
                'id' => $this->temple->id,
                'slug' => $this->temple->slug,
                'name' => $this->temple->translate('name', app()->getLocale(), reviewedOnly: true),
            ] : null),

            'visit_id' => $this->devotee_visit_id,

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
