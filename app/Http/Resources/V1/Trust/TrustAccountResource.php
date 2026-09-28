<?php

namespace App\Http\Resources\V1\Trust;

use App\Models\TempleSuggestion;
use App\Models\TempleUser;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The signed-in temple team member, with everything the app's home screen
 * needs to decide what to show: the temples they manage, the claims still
 * waiting, and the temples they registered.
 *
 * @mixin User
 */
class TrustAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $temples = $this->temples()
            ->with(['deity:id,name', 'state:id,name', 'primaryPhoto'])
            ->orderBy('name')
            ->get();

        $claims = $this->templeClaims()
            ->with('temple:id,slug,name,city')
            ->latest('id')
            ->get();

        $registrations = TempleSuggestion::query()
            ->where('user_id', $this->id)
            ->with('state:id,name')
            ->latest('id')
            ->get();

        return [
            'user' => [
                'id' => $this->id,
                'name' => $this->name,
                'email' => $this->email,
                'phone' => $this->phone,
                'role' => $this->role?->value,
                'is_super_admin' => $this->isSuperAdmin(),
            ],
            'temples' => TrustTempleResource::collection($temples)->resolve($request),
            'claims' => $claims->map(fn (TempleUser $claim): array => self::claim($claim))->values(),
            'registrations' => $registrations->map(fn (TempleSuggestion $s): array => self::registration($s))->values(),
        ];
    }

    public static function claim(TempleUser $claim): array
    {
        return [
            'id' => $claim->getKey(),
            'temple' => $claim->temple === null ? null : [
                'id' => $claim->temple->getKey(),
                'slug' => $claim->temple->slug,
                'name' => $claim->temple->name,
                'city' => $claim->temple->city,
            ],
            'role' => $claim->role,
            'status' => $claim->status(),
            'note' => $claim->claim_note,
            'rejection_reason' => $claim->rejection_reason,
            'requested_at' => $claim->requested_at?->toIso8601String(),
            'approved_at' => $claim->approved_at?->toIso8601String(),
        ];
    }

    public static function registration(TempleSuggestion $s): array
    {
        return [
            'id' => $s->getKey(),
            'name' => $s->name,
            'city' => $s->city,
            'state' => $s->state?->name,
            'status' => [
                'value' => $s->status?->value,
                'label' => $s->status?->getLabel(),
            ],
            'review_note' => $s->review_note,
            'temple_id' => $s->temple_id,
            'created_at' => $s->created_at?->toIso8601String(),
        ];
    }
}
