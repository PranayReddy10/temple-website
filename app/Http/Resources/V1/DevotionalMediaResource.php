<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\DevotionalMedia */
class DevotionalMediaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type?->value,
            'title' => $this->title,
            'description' => $this->description,

            // 'external' means the client should open or embed the link where
            // it is officially published rather than stream it from us.
            'source_type' => $this->source_type,
            'url' => $this->url(),
            'thumbnail_url' => $this->thumbnailUrl(),

            'duration_seconds' => $this->duration_seconds,
            'duration_label' => $this->durationLabel(),

            /*
             * Rights travel with the media. A client that plays a recording
             * without showing the artist and licence strips the terms we are
             * publishing it under, so these are part of the payload rather
             * than optional extras.
             */
            'artist' => $this->artist,
            'credit' => $this->credit,
            'license' => $this->license,
            'license_url' => $this->license_url,
        ];
    }
}
