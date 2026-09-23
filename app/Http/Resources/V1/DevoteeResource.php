<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Devotee */
class DevoteeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'avatar_url' => $this->avatarUrl(),
            'locale' => $this->locale,
            'home_state' => $this->whenLoaded('homeState', fn () => $this->homeState?->name),
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'gender' => $this->gender?->value,
            'gender_label' => $this->gender?->getLabel(),
            'is_verified' => $this->isVerified(),
            'joined_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
