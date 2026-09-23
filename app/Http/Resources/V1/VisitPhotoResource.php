<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\VisitPhoto */
class VisitPhotoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'temple_id' => $this->temple_id,
            'visit_id' => $this->devotee_visit_id,
            // stamp: the one in the passport. memory: one of up to three
            // kept with the visit, never shown to anyone else.
            'kind' => $this->kind ?? 'stamp',

            // Both, always. The stamp is what gets shared; the original is
            // what the devotee keeps, and losing it is not recoverable.
            'original_url' => $this->originalUrl(),
            'stamp_url' => $this->stampUrl(),
            'has_stamp' => $this->hasStamp(),

            'caption' => $this->caption,

            /*
             * Moderation state is returned to the owner because silence looks
             * like failure: a devotee who uploads a photo and sees nothing
             * happen will upload it again.
             */
            'status' => [
                'value' => $this->status?->value,
                'label' => $this->status?->getLabel(),
            ],
            'moderation_note' => $this->moderation_note,

            'is_public' => (bool) $this->is_public,
            'is_visible_to_others' => $this->isVisibleToOthers(),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
