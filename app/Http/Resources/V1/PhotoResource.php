<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\TemplePhoto */
class PhotoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category' => $this->category?->value,
            'caption' => $this->caption,
            'is_primary' => $this->is_primary,
            'urls' => [
                'original' => $this->url(),
                'medium' => $this->mediumUrl(),
                'thumbnail' => $this->thumbnailUrl(),
            ],
            'width' => $this->width,
            'height' => $this->height,
            // Attribution travels with the image. A client that displays the
            // photo without the credit would strip the licence terms with it.
            'credit' => $this->credit,
            'source_url' => $this->source_url,
            'license' => $this->license,
        ];
    }
}
