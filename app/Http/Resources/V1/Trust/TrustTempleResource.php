<?php

namespace App\Http\Resources\V1\Trust;

use App\Enums\TempleStatus;
use App\Http\Resources\V1\PhotoResource;
use App\Models\Temple;
use App\Models\TempleUser;
use App\Support\Seo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A temple as its own team sees it in the trust app.
 *
 * The editable fields are the ones the temple portal lets a team change;
 * identity, taxonomy and trust level are shown but stay with the editors.
 *
 * @mixin Temple
 */
class TrustTempleResource extends JsonResource
{
    /** @var array<string, int>|null */
    public ?array $stats = null;

    public function withStats(array $stats): static
    {
        $this->stats = $stats;

        return $this;
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'deity' => $this->deity?->name,
            'state' => $this->state?->name,
            'city' => $this->city,
            'status' => [
                'value' => $this->status?->value,
                'label' => $this->status?->getLabel(),
            ],
            'trust' => [
                'level' => $this->verification_status?->value,
                'label' => $this->verification_status?->getLabel(),
            ],
            'access_level' => $this->pivot?->role ?? $this->accessLevelFor($request),
            // The temple's page on the website, to share; only once published.
            'public_url' => $this->status === TempleStatus::Published ? Seo::url('temples/'.$this->slug) : null,
            'primary_photo' => $this->relationLoaded('primaryPhoto') && $this->primaryPhoto
                ? new PhotoResource($this->primaryPhoto)
                : null,

            // What the team may edit.
            'profile' => [
                'short_description' => $this->short_description,
                'address' => $this->address,
                'city' => $this->city,
                'district' => $this->district?->name,
                'state_id' => $this->state_id,
                'state' => $this->state?->name,
                'pincode' => $this->pincode,
                'latitude' => $this->latitude !== null ? (float) $this->latitude : null,
                'longitude' => $this->longitude !== null ? (float) $this->longitude : null,
                'official_website' => $this->official_website,
                'contact_phone' => $this->contact_phone,
                'contact_email' => $this->contact_email,
                'dress_code' => $this->dress_code,
                'photography_policy' => $this->photography_policy,
                'mobile_policy' => $this->mobile_policy,
                'footwear_policy' => $this->footwear_policy,
                'entry_rules' => $this->entry_rules,
                'queue_information' => $this->queue_information,
            ],

            'stats' => $this->when($this->stats !== null, fn () => $this->stats),
        ];
    }

    /** The signed-in team member's role here, when the temple was not loaded through their list. */
    protected function accessLevelFor(Request $request): ?string
    {
        $user = $request->user('trust');

        return $user === null ? null : TempleUser::query()->approved()
            ->where('temple_id', $this->id)->where('user_id', $user->getKey())->value('role');
    }
}
