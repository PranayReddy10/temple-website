<?php

namespace App\Http\Controllers\Api\V1\Trust;

use App\Enums\EventStatus;
use App\Enums\TempleStatus;
use App\Enums\TempleSuggestionStatus;
use App\Http\Controllers\Api\V1\Trust\Concerns\ScopesToTrustTemples;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\EventResource;
use App\Models\Temple;
use App\Models\TempleEvent;
use App\Models\TempleSuggestion;
use App\Models\TempleUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The super admin's queues, from the trust app: who wants to manage which
 * temple, temples waiting to be listed, and events waiting for review.
 *
 * The same decisions as the admin panel (Temple access, Temple suggestions,
 * Temple events), written the same way, so either place can finish what the
 * other started. Only a super admin reaches these routes.
 */
class TrustAdminController extends Controller
{
    use ScopesToTrustTemples;

    public function overview(): JsonResponse
    {
        return response()->json(['data' => [
            'claims_pending' => TempleUser::query()->pending()->count(),
            'registrations_pending' => TempleSuggestion::query()->pending()->count(),
            'events_in_review' => TempleEvent::query()->awaitingReview()->count(),
            'temples' => [
                'published' => Temple::query()->where('status', TempleStatus::Published)->count(),
                'draft' => Temple::query()->where('status', TempleStatus::Draft)->count(),
                'in_review' => Temple::query()->where('status', TempleStatus::InReview)->count(),
            ],
        ]]);
    }

    // --- Claims -------------------------------------------------------------

    public function claims(Request $request): JsonResponse
    {
        $status = $request->validate(['status' => ['nullable', Rule::in(['pending', 'approved', 'rejected'])]])['status'] ?? 'pending';

        $rows = TempleUser::query()
            ->{$status}()
            ->with(['temple:id,slug,name,city,status', 'user:id,name,email,phone'])
            ->latest('id')
            ->limit(200)
            ->get();

        return response()->json(['data' => $rows->map(fn (TempleUser $c): array => $this->claim($c))->values()]);
    }

    public function approveClaim(Request $request, int $claim): JsonResponse
    {
        $row = TempleUser::query()->findOrFail($claim);

        $row->forceFill([
            'approved_at' => now(),
            'approved_by' => $this->trustUser($request)->getKey(),
            'rejection_reason' => null,
        ])->save();

        return response()->json(['data' => $this->claim($row->load(['temple', 'user']))]);
    }

    public function rejectClaim(Request $request, int $claim): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $row = TempleUser::query()->findOrFail($claim);

        $row->forceFill([
            'approved_at' => null,
            'approved_by' => null,
            'rejection_reason' => $validated['reason'],
        ])->save();

        return response()->json(['data' => $this->claim($row->load(['temple', 'user']))]);
    }

    // --- Temple registrations and suggestions -------------------------------

    public function registrations(Request $request): JsonResponse
    {
        $status = $request->validate(['status' => ['nullable', Rule::enum(TempleSuggestionStatus::class)]])['status'] ?? 'pending';

        $rows = TempleSuggestion::query()
            ->where('status', $status)
            ->with(['state:id,name', 'deity:id,name', 'photos', 'user:id,name,email,phone', 'devotee:id,name', 'temple:id,name'])
            ->latest('id')
            ->limit(200)
            ->get();

        return response()->json(['data' => $rows->map(fn (TempleSuggestion $s): array => $this->registration($s))->values()]);
    }

    /** Lists it as a draft temple; the registrant gets a pending claim. */
    public function approveRegistration(Request $request, int $registration): JsonResponse
    {
        $row = $this->pendingRegistration($registration);
        $temple = $row->createTemple($this->trustUser($request)->getKey());

        return response()->json(['data' => $this->registration($row->refresh()->load(['photos', 'temple:id,name'])) + ['created_temple_id' => $temple->getKey()]]);
    }

    public function duplicateRegistration(Request $request, int $registration): JsonResponse
    {
        $validated = $request->validate([
            'temple_id' => ['required', 'integer', 'exists:temples,id'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $row = $this->pendingRegistration($registration);
        $row->review(TempleSuggestionStatus::Duplicate, $this->trustUser($request)->getKey(), $validated['note'] ?? null, (int) $validated['temple_id']);

        return response()->json(['data' => $this->registration($row->refresh()->load(['photos', 'temple:id,name']))]);
    }

    public function rejectRegistration(Request $request, int $registration): JsonResponse
    {
        $validated = $request->validate(['note' => ['required', 'string', 'max:1000']]);

        $row = $this->pendingRegistration($registration);
        $row->review(TempleSuggestionStatus::Rejected, $this->trustUser($request)->getKey(), $validated['note']);

        return response()->json(['data' => $this->registration($row->refresh()->load('photos'))]);
    }

    // --- Events -------------------------------------------------------------

    public function events(): JsonResponse
    {
        $rows = TempleEvent::query()->awaitingReview()->with('temple:id,slug,name,city')->oldest('starts_on')->limit(200)->get();

        return response()->json(['data' => $rows->map(fn (TempleEvent $e): array => $this->event($e))->values()]);
    }

    public function approveEvent(Request $request, int $event): JsonResponse
    {
        $row = TempleEvent::query()->awaitingReview()->findOrFail($event);
        $row->update(['status' => EventStatus::Published, 'reviewed_by' => $this->trustUser($request)->getKey(), 'review_note' => null]);

        return response()->json(['data' => $this->event($row->load('temple'))]);
    }

    public function rejectEvent(Request $request, int $event): JsonResponse
    {
        $validated = $request->validate(['note' => ['required', 'string', 'max:1000']]);
        $row = TempleEvent::query()->awaitingReview()->findOrFail($event);
        $row->update(['status' => EventStatus::Rejected, 'reviewed_by' => $this->trustUser($request)->getKey(), 'review_note' => $validated['note']]);

        return response()->json(['data' => $this->event($row->load('temple'))]);
    }

    // --- Temples ------------------------------------------------------------

    /** Draft → in review → published, and back. TempleObserver still decides who may publish. */
    public function templeStatus(Request $request, int $temple): JsonResponse
    {
        $validated = $request->validate(['status' => ['required', Rule::enum(TempleStatus::class)]]);
        $record = $this->managedTemple($request, $temple);
        $record->update(['status' => $validated['status']]);

        return response()->json(['data' => [
            'id' => $record->getKey(),
            'status' => ['value' => $record->status->value, 'label' => $record->status->getLabel()],
        ]]);
    }

    // --- Shapes -------------------------------------------------------------

    protected function pendingRegistration(int $id): TempleSuggestion
    {
        $row = TempleSuggestion::query()->findOrFail($id);
        abort_unless($row->status === TempleSuggestionStatus::Pending, 422, 'This temple has already been reviewed.');

        return $row;
    }

    /** @return array<string, mixed> */
    protected function claim(TempleUser $c): array
    {
        return [
            'id' => $c->getKey(),
            'status' => $c->status(),
            'role' => $c->role,
            'note' => $c->claim_note,
            'location' => $c->hasClaimLocation() ? [
                'latitude' => (float) $c->claim_latitude,
                'longitude' => (float) $c->claim_longitude,
                'accuracy_m' => $c->claim_accuracy_m,
                'distance_m' => $c->claim_distance_m,
                'summary' => $c->claimLocationSummary(),
                'map_url' => $c->claimMapUrl(),
            ] : null,
            'rejection_reason' => $c->rejection_reason,
            'requested_at' => $c->requested_at?->toIso8601String(),
            'temple' => $c->temple === null ? null : [
                'id' => $c->temple->getKey(),
                'name' => $c->temple->name,
                'city' => $c->temple->city,
                'status' => $c->temple->status?->value,
            ],
            'user' => $c->user === null ? null : [
                'id' => $c->user->getKey(),
                'name' => $c->user->name,
                'email' => $c->user->email,
                'phone' => $c->user->phone,
            ],
        ];
    }

    /** @return array<string, mixed> */
    protected function registration(TempleSuggestion $s): array
    {
        return [
            'id' => $s->getKey(),
            'name' => $s->name,
            'alternate_names' => $s->alternate_names,
            'deity' => $s->deity?->name ?? $s->deity_name,
            'address' => $s->address,
            'city' => $s->city,
            'district' => $s->district,
            'state' => $s->state?->name,
            'pincode' => $s->pincode,
            'description' => $s->description,
            'opens_at' => $s->opens_at ? substr((string) $s->opens_at, 0, 5) : null,
            'closes_at' => $s->closes_at ? substr((string) $s->closes_at, 0, 5) : null,
            'contact_phone' => $s->contact_phone,
            'status' => ['value' => $s->status?->value, 'label' => $s->status?->getLabel()],
            'review_note' => $s->review_note,
            'from_trust_app' => $s->user_id !== null,
            'submitter' => [
                'role' => $s->roleLabel(),
                'name' => $s->submitter_name ?? $s->user?->name ?? $s->devotee?->name,
                'phone' => $s->submitter_phone ?? $s->user?->phone,
                'email' => $s->user?->email,
                'note' => $s->submitter_note,
            ],
            'photos' => $s->relationLoaded('photos') ? $s->photos->map->url()->filter()->values() : [],
            'temple' => $s->temple === null ? null : ['id' => $s->temple->getKey(), 'name' => $s->temple->name],
            'created_at' => $s->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    protected function event(TempleEvent $e): array
    {
        return (new EventResource($e))->resolve(request()) + [
            'status' => ['value' => $e->status?->value, 'label' => $e->status?->getLabel()],
            'review_note' => $e->review_note,
        ];
    }
}
