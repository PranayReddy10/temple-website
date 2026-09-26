<?php

namespace App\Http\Resources\V1;

use App\Enums\SevaDriveStatus;
use App\Models\Devotee;
use App\Models\SevaDriveMedia;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A seva drive, as the person asking is allowed to see it.
 *
 * Three readers get three shapes from one resource: anybody sees the place,
 * the plan and the photographs; a volunteer who has joined also sees the
 * organiser's phone number; the organiser also sees what staff said. The UPI
 * ID appears for nobody until staff have verified the result.
 *
 * @mixin \App\Models\SevaDrive
 */
class SevaDriveResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Devotee|null $viewer */
        $viewer = $request->user('devotee');
        $isOrganiser = $this->isOrganisedBy($viewer);
        $hasJoined = $this->relationLoaded('volunteers')
            ? $viewer !== null && $this->volunteers->contains('devotee_id', $viewer->getKey())
            : $this->hasVolunteer($viewer);

        $media = $this->relationLoaded('media')
            ? $this->media->filter(fn (SevaDriveMedia $m): bool => ! $m->is_hidden || $isOrganiser)
            : collect();

        $headcount = $this->volunteers_sum_party_size ?? ($this->relationLoaded('volunteers')
            ? $this->volunteers->sum('party_size')
            : $this->headcount());

        $cover = $media->first(fn (SevaDriveMedia $m): bool => $m->type === SevaDriveMedia::TYPE_PHOTO
            && $m->stage === SevaDriveMedia::STAGE_AFTER)
            ?? $media->first(fn (SevaDriveMedia $m): bool => $m->type === SevaDriveMedia::TYPE_PHOTO);

        $acceptsDonations = $this->acceptsDonations();

        return [
            'id' => $this->id,
            'title' => $this->title,
            'cause' => [
                'value' => $this->cause?->value,
                'label' => $this->cause?->getLabel(),
            ],
            // Where it is in its life. An open drive whose last day has passed
            // reads as completed, whether or not the organiser said so.
            'status' => [
                'value' => $this->effectiveStatus()?->value,
                'label' => $this->effectiveStatus()?->getLabel(),
            ],

            // A badge, separate from the status: verifying a drive does not
            // end it, and an unverified drive is still listed.
            'is_verified' => $this->isVerified(),
            'verification' => [
                'verified_at' => $this->verified_at?->toIso8601String(),
                'requested' => $this->verificationPending(),
                'requested_at' => $this->verificationPending() ? $this->verification_requested_at?->toIso8601String() : null,
            ],

            // Staff flagged it: still shown, with this warning, and closed to
            // joining and donations.
            'is_misleading' => (bool) $this->is_misleading,
            'misleading_note' => $this->is_misleading ? $this->misleading_note : null,

            'place' => [
                'name' => $this->place_name,
                'address' => $this->address,
                'city' => $this->city,
                'district' => $this->district,
                'pincode' => $this->pincode,
                'state' => $this->whenLoaded('state', fn () => $this->state?->name),
                'latitude' => $this->latitude,
                'longitude' => $this->longitude,
                'meeting_point' => $this->meeting_point,
            ],
            'temple' => $this->whenLoaded('temple', fn () => $this->temple ? [
                'id' => $this->temple->id,
                'slug' => $this->temple->slug,
                'name' => $this->temple->name,
            ] : null),

            'problem' => $this->problem,
            'plan' => $this->plan,
            'what_to_bring' => $this->what_to_bring,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'is_multi_day' => $this->isMultiDay(),
            'date_label' => $this->dateLabel(),

            'volunteers_needed' => $this->volunteers_needed,
            'volunteers_joined' => (int) $headcount,
            'signups' => (int) ($this->volunteers_count ?? $this->volunteers()->count()),

            'organiser' => [
                'name' => $this->organiserName(),
                'avatar_url' => $this->relationLoaded('organiser') ? $this->organiser?->avatarUrl() : null,
                // Run by staff rather than a devotee.
                'is_team' => $this->devotee_id === null,
            ],
            // The organiser's phone is for the people who are coming.
            'contact_phone' => $isOrganiser || $hasJoined ? $this->contact_phone : null,

            'cover_url' => $cover?->url(),
            'media' => $this->whenLoaded('media', fn () => [
                'before' => SevaDriveMediaResource::collection($media->where('stage', SevaDriveMedia::STAGE_BEFORE)->values()),
                'after' => SevaDriveMediaResource::collection($media->where('stage', SevaDriveMedia::STAGE_AFTER)->values()),
            ]),

            'completion_note' => $this->completion_note,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'verified_at' => $this->verified_at?->toIso8601String(),

            'donations' => [
                'open' => $acceptsDonations,
                'upi_id' => $acceptsDonations ? $this->upi_id : null,
                'upi_name' => $acceptsDonations ? ($this->upi_name ?: $this->organiser?->name) : null,
                'upi_link' => $this->upiLink(),
                'goal' => $this->donation_goal,
                'purpose' => $this->donation_purpose,
                // Confirmed amounts only, for everybody: how much a drive has
                // raised is not a secret, and it is the answer to "is anyone
                // supporting this?".
                'raised' => (int) ($this->donations_raised ?? $this->confirmedDonationTotal()),
                'donors' => (int) ($this->donors_count ?? $this->donations()->whereNotNull('confirmed_at')->count()),
            ],

            'viewer' => [
                'is_organiser' => $isOrganiser,
                'has_joined' => $hasJoined,
                'can_join' => $viewer !== null && ! $isOrganiser && ! $hasJoined
                    && $this->acceptsVolunteers(),
                'can_edit' => $isOrganiser && $this->status?->isEditableByOrganiser() === true,
                'can_complete' => $isOrganiser && $this->status === SevaDriveStatus::Approved
                    && $this->starts_at?->isPast() === true,
                'can_leave' => $hasJoined && ! $this->hasEnded()
                    && $this->status === SevaDriveStatus::Approved,
                'can_request_verification' => $isOrganiser && ! $this->isVerified()
                    && ! $this->verificationPending()
                    && $this->status?->isPublic() === true,
            ],

            // For the organiser only: what they entered and what staff said.
            'mine' => $this->when($isOrganiser, fn () => [
                'upi_id' => $this->upi_id,
                'upi_name' => $this->upi_name,
                'donations_enabled' => $this->donations_enabled,
                'moderation_note' => $this->moderation_note,
                'block_reason' => $this->block_reason,
            ]),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
