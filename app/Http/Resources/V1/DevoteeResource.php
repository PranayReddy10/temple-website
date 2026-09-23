<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Devotee */
class DevoteeResource extends JsonResource
{
    /** @return array<string, mixed>|null */
    protected function currentSubscriptionSummary(): ?array
    {
        $current = $this->resource->currentSubscription();

        return $current === null ? null : [
            'plan' => $current->plan?->name,
            'plan_code' => $current->plan?->code,
            'ends_at' => $current->ends_at->toIso8601String(),
        ];
    }

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
            // For the app's push topic "state-{id}".
            'home_state_id' => $this->home_state_id,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'gender' => $this->gender?->value,
            'gender_label' => $this->gender?->getLabel(),
            'is_verified' => $this->isVerified(),
            // What the devotee's own passport QR carries. Only ever returned
            // to the devotee themselves: this resource is the /me response.
            'passport_url' => \App\Support\PassportQr::url($this->resource),
            'sign_in_methods' => array_values(array_filter([
                filled($this->password) ? 'password' : null,
                filled($this->google_id) ? 'google' : null,
                filled($this->apple_id) ? 'apple' : null,
            ])),
            // What the app unlocks, and the plan behind it. Decided here, so
            // a device cannot award itself a plan.
            'entitlements' => $this->resource->entitlements(),
            'subscription' => $this->whenNotNull($this->currentSubscriptionSummary()),
            'joined_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
