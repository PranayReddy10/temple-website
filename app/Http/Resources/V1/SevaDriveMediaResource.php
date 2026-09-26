<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\SevaDriveMedia */
class SevaDriveMediaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'stage' => $this->stage,
            'type' => $this->type,
            'url' => $this->url(),
            // An uploaded file plays in the app; a link opens where it lives.
            'is_link' => blank($this->path) && filled($this->video_url),
            'caption' => $this->caption,
        ];
    }
}
