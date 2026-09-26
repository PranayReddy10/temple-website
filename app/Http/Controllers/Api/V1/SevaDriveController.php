<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SevaCause;
use App\Enums\SevaDriveStatus;
use App\Enums\TempleStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\SevaDriveMediaResource;
use App\Http\Resources\V1\SevaDriveResource;
use App\Models\Devotee;
use App\Models\SevaDrive;
use App\Models\SevaDriveDonation;
use App\Models\SevaDriveMedia;
use App\Models\Temple;
use App\Support\UploadRules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Seva drives: devotees organising the care of an old temple or heritage
 * place, and others joining them.
 *
 * The life of a drive, and who moves it along:
 *
 *   organiser raises it (pending) → staff approve (approved: open for
 *   volunteers) → the day comes, the organiser adds after-photos and marks it
 *   done (completed) → staff check the before and after (verified) → the UPI
 *   ID is served and donations open.
 *
 * Every step a devotee takes is checked against who they are here, never
 * against anything the request says about them.
 */
class SevaDriveController extends Controller
{
    /** Causes, and the limits the app should enforce before uploading. */
    public function options(): JsonResponse
    {
        return response()->json(['data' => [
            'causes' => collect(SevaCause::cases())->map(fn (SevaCause $cause): array => [
                'value' => $cause->value,
                'label' => $cause->getLabel(),
                'description' => $cause->description(),
            ])->values(),
            'max_media_per_stage' => SevaDriveMedia::MAX_PER_STAGE,
            'photo_max_kb' => UploadRules::maxKbFor('seva_photo'),
            'video_max_kb' => UploadRules::maxKbFor('seva_video'),
        ]]);
    }

    /**
     * Public listing. `when=upcoming` (the default) is what can still be
     * joined; `when=done` is the finished work, verified first.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'when' => ['nullable', 'in:upcoming,done,all'],
            'cause' => ['nullable', Rule::enum(SevaCause::class)],
            'temple' => ['nullable', 'string', 'max:191'],
            'state_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
            'verified' => ['nullable', 'boolean'],
        ]);

        $query = $this->withListing(SevaDrive::query()->publiclyVisible());

        match ($request->input('when', 'upcoming')) {
            'upcoming' => $query->where('status', SevaDriveStatus::Approved)
                // A drive that started this morning can still be joined.
                ->where(fn (Builder $q) => $q->where('starts_at', '>=', now()->startOfDay())
                    ->orWhere('ends_at', '>=', now()))
                ->orderBy('starts_at'),
            'done' => $query->whereIn('status', [SevaDriveStatus::Completed, SevaDriveStatus::Verified])
                ->orderByRaw('case when status = ? then 0 else 1 end', [SevaDriveStatus::Verified->value])
                ->latest('completed_at'),
            default => $query->latest('starts_at'),
        };

        if ($request->filled('cause')) {
            $query->where('cause', $request->input('cause'));
        }

        if ($request->filled('temple')) {
            $query->whereHas('temple', fn (Builder $q) => $q->where('slug', $request->input('temple')));
        }

        if ($request->boolean('verified')) {
            $query->where('status', SevaDriveStatus::Verified);
        }

        if ($request->filled('state_id')) {
            $query->where('state_id', $request->integer('state_id'));
        }

        if ($request->filled('q')) {
            $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $request->input('q')).'%';
            $query->where(fn (Builder $q) => $q->where('title', 'like', $term)
                ->orWhere('place_name', 'like', $term)
                ->orWhere('city', 'like', $term)
                ->orWhere('district', 'like', $term)
                ->orWhere('pincode', $request->input('q')));
        }

        return SevaDriveResource::collection(
            $query->paginate(min(50, max(1, (int) $request->integer('per_page', 20))))
        );
    }

    public function show(Request $request, SevaDrive $drive): SevaDriveResource
    {
        $viewer = $request->user('devotee');

        // Not yet approved, turned down or called off: the organiser's alone.
        if (! $drive->status->isPublic() && ! $drive->isOrganisedBy($viewer)) {
            throw new NotFoundHttpException();
        }

        return new SevaDriveResource($this->loadDetail($drive));
    }

    /** Drives I organised, or (`scope=joined`) the ones I am going to. */
    public function mine(Request $request): AnonymousResourceCollection
    {
        $devotee = $this->devotee($request);

        $query = $request->input('scope') === 'joined'
            ? SevaDrive::query()->publiclyVisible()
                ->whereHas('volunteers', fn (Builder $q) => $q->where('devotee_id', $devotee->getKey()))
            : SevaDrive::query()->where('devotee_id', $devotee->getKey());

        return SevaDriveResource::collection(
            $this->withListing($query)->with('volunteers:id,seva_drive_id,devotee_id,party_size')->latest('starts_at')->paginate(50)
        );
    }

    /**
     * Raise a drive, with at least one photograph of the place as it is.
     *
     * Multipart: the fields below, plus `photos[]`, an optional `video` and
     * an optional `video_url`. Everything is written or nothing is.
     */
    public function store(Request $request): JsonResponse
    {
        $devotee = $this->devotee($request);

        $validated = $request->validate([
            ...$this->detailRules(),
            ...$this->mediaRules(required: true),
        ]);

        $drive = DB::transaction(function () use ($request, $devotee, $validated): SevaDrive {
            $drive = new SevaDrive($this->fillableFrom($validated));
            $drive->devotee_id = $devotee->getKey();
            $drive->temple_id = $this->templeId($validated['temple'] ?? null);
            $drive->save();

            $this->storeMedia($request, $drive, $devotee, SevaDriveMedia::STAGE_BEFORE);

            return $drive;
        });

        return (new SevaDriveResource($this->loadDetail($drive)))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Change a drive.
     *
     * Before approval anything may change, and a drive staff turned down goes
     * back into the queue when it is edited. Once approved, volunteers have
     * signed up for what staff saw, so only the arrangements may change — not
     * the place, the cause or the plan.
     */
    public function update(Request $request, SevaDrive $drive): SevaDriveResource
    {
        $devotee = $this->devotee($request);
        $this->ownedBy($drive, $devotee);

        if (! $drive->status->isEditableByOrganiser()) {
            throw ValidationException::withMessages(['status' => 'A drive that is finished or cancelled can no longer be changed.']);
        }

        $rules = collect($this->detailRules(partial: true));

        // An end time is checked against the start being sent, or else the
        // one already on the drive.
        if (! $request->has('starts_at')) {
            $rules['ends_at'] = ['nullable', 'date', 'after:'.$drive->starts_at->toIso8601String()];
        }

        if ($drive->status === SevaDriveStatus::Approved) {
            $rules = $rules->only([
                'meeting_point', 'what_to_bring', 'ends_at', 'volunteers_needed',
                'contact_phone', 'upi_id', 'upi_name', 'donation_goal', 'donation_purpose',
            ]);
        }

        $validated = $request->validate($rules->all());

        $drive->fill($this->fillableFrom($validated));

        if (array_key_exists('temple', $validated) && $drive->status !== SevaDriveStatus::Approved) {
            $drive->temple_id = $this->templeId($validated['temple']);
        }

        if ($drive->status === SevaDriveStatus::Rejected) {
            $drive->status = SevaDriveStatus::Pending;
        }

        $drive->save();

        return new SevaDriveResource($this->loadDetail($drive));
    }

    /** Add photographs or a video, before (while planning) or after (once it has happened). */
    public function addMedia(Request $request, SevaDrive $drive): JsonResponse
    {
        $devotee = $this->devotee($request);
        $this->ownedBy($drive, $devotee);

        $validated = $request->validate([
            'stage' => ['required', 'in:before,after'],
            ...$this->mediaRules(required: true),
        ]);

        $stage = $validated['stage'];

        if ($stage === SevaDriveMedia::STAGE_BEFORE && ! $drive->status->isEditableByOrganiser()) {
            throw ValidationException::withMessages(['stage' => 'The before photographs are closed once a drive is finished.']);
        }

        if ($stage === SevaDriveMedia::STAGE_AFTER && ! $this->canAddAfter($drive)) {
            throw ValidationException::withMessages(['stage' => 'After photographs can be added once the drive has started.']);
        }

        $added = DB::transaction(fn () => $this->storeMedia($request, $drive, $devotee, $stage));

        // New pictures of a verified drive have not been verified.
        if ($stage === SevaDriveMedia::STAGE_AFTER && $drive->status === SevaDriveStatus::Verified) {
            $drive->status = SevaDriveStatus::Completed;
            $drive->save();
        }

        return response()->json(['data' => SevaDriveMediaResource::collection($added)], 201);
    }

    public function destroyMedia(Request $request, SevaDrive $drive, SevaDriveMedia $media): JsonResponse
    {
        $this->ownedBy($drive, $this->devotee($request));

        if ($media->seva_drive_id !== $drive->getKey()) {
            throw new NotFoundHttpException();
        }

        // Verified evidence stays; removing it would un-verify the drive
        // without anybody noticing.
        if ($drive->status === SevaDriveStatus::Verified) {
            throw ValidationException::withMessages(['media' => 'Photographs of a verified drive cannot be removed. Write to support if one needs to come down.']);
        }

        $media->delete();

        return response()->json(['data' => ['message' => 'Removed.']]);
    }

    /** The organiser says it is done, with after-photographs to show it. */
    public function complete(Request $request, SevaDrive $drive): SevaDriveResource
    {
        $this->ownedBy($drive, $this->devotee($request));

        $validated = $request->validate([
            'completion_note' => ['required', 'string', 'min:20', 'max:3000'],
        ]);

        if ($drive->status !== SevaDriveStatus::Approved) {
            throw ValidationException::withMessages(['status' => 'Only an approved drive can be marked as done.']);
        }

        if ($drive->starts_at->isFuture()) {
            throw ValidationException::withMessages(['status' => 'The drive has not started yet.']);
        }

        if (! $drive->media()->where('stage', SevaDriveMedia::STAGE_AFTER)->exists()) {
            throw ValidationException::withMessages(['media' => 'Add at least one photograph of the place afterwards, so the work can be verified.']);
        }

        $drive->completion_note = $validated['completion_note'];
        $drive->completed_at = now();
        $drive->status = SevaDriveStatus::Completed;
        $drive->save();

        return new SevaDriveResource($this->loadDetail($drive));
    }

    public function cancel(Request $request, SevaDrive $drive): SevaDriveResource
    {
        $this->ownedBy($drive, $this->devotee($request));

        if (! in_array($drive->status, [SevaDriveStatus::Pending, SevaDriveStatus::Approved, SevaDriveStatus::Rejected], true)) {
            throw ValidationException::withMessages(['status' => 'A finished drive cannot be cancelled.']);
        }

        $drive->status = SevaDriveStatus::Cancelled;
        $drive->save();

        return new SevaDriveResource($this->loadDetail($drive));
    }

    // --- Volunteers ---

    public function join(Request $request, SevaDrive $drive): SevaDriveResource
    {
        $devotee = $this->devotee($request);
        $this->visibleTo($drive, $devotee);

        if ($drive->isOrganisedBy($devotee)) {
            throw ValidationException::withMessages(['drive' => 'You are organising this drive.']);
        }

        if (! $drive->acceptsVolunteers()) {
            throw ValidationException::withMessages(['drive' => 'This drive is no longer taking volunteers.']);
        }

        $validated = $request->validate([
            'party_size' => ['nullable', 'integer', 'min:1', 'max:20'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        // Joining twice updates the one sign-up rather than failing.
        $drive->volunteers()->updateOrCreate(
            ['devotee_id' => $devotee->getKey()],
            ['party_size' => $validated['party_size'] ?? 1, 'note' => $validated['note'] ?? null],
        );

        return new SevaDriveResource($this->loadDetail($drive));
    }

    public function leave(Request $request, SevaDrive $drive): SevaDriveResource
    {
        $devotee = $this->devotee($request);
        $this->visibleTo($drive, $devotee);

        $drive->volunteers()->where('devotee_id', $devotee->getKey())->delete();

        return new SevaDriveResource($this->loadDetail($drive));
    }

    /** Who is coming, for the organiser. Names only; nobody's contact details. */
    public function volunteers(Request $request, SevaDrive $drive): JsonResponse
    {
        $this->ownedBy($drive, $this->devotee($request));

        $rows = $drive->volunteers()->with('devotee:id,name,avatar_disk,avatar_path')->get()
            ->map(fn ($v): array => [
                'id' => $v->id,
                'name' => $v->devotee?->name,
                'avatar_url' => $v->devotee?->avatarUrl(),
                'party_size' => $v->party_size,
                'note' => $v->note,
                'attended' => $v->attended,
                'joined_at' => $v->created_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $rows]);
    }

    // --- Donations ---

    /**
     * Record what a donor says they sent.
     *
     * The platform never sees the money; this is how the organiser hears who
     * sent what, and how the goal fills once they confirm it.
     */
    public function donate(Request $request, SevaDrive $drive): JsonResponse
    {
        $devotee = $this->devotee($request);
        $this->visibleTo($drive, $devotee);

        if (! $drive->acceptsDonations()) {
            throw ValidationException::withMessages(['drive' => 'This drive is not taking donations.']);
        }

        $validated = $request->validate([
            'amount' => ['required', 'integer', 'min:1', 'max:10000000'],
            'upi_ref' => ['nullable', 'string', 'max:40'],
            'message' => ['nullable', 'string', 'max:255'],
            'is_anonymous' => ['nullable', 'boolean'],
        ]);

        $donation = $drive->donations()->create([
            'devotee_id' => $devotee->getKey(),
            'amount' => $validated['amount'],
            'upi_ref' => $validated['upi_ref'] ?? null,
            'message' => $validated['message'] ?? null,
            'is_anonymous' => $request->boolean('is_anonymous'),
        ]);

        return response()->json(['data' => $this->donationRow($donation, true)], 201);
    }

    /** For the organiser: every donation reported, to confirm against their UPI app. */
    public function donations(Request $request, SevaDrive $drive): JsonResponse
    {
        $this->ownedBy($drive, $this->devotee($request));

        return response()->json([
            'data' => $drive->donations()->with('devotee:id,name')->get()
                ->map(fn (SevaDriveDonation $d): array => $this->donationRow($d, true)),
        ]);
    }

    public function confirmDonation(Request $request, SevaDrive $drive, SevaDriveDonation $donation): JsonResponse
    {
        $this->ownedBy($drive, $this->devotee($request));

        if ($donation->seva_drive_id !== $drive->getKey()) {
            throw new NotFoundHttpException();
        }

        $donation->confirmed_at = $request->boolean('received', true) ? now() : null;
        $donation->save();

        return response()->json(['data' => $this->donationRow($donation->load('devotee:id,name'), true)]);
    }

    // --- Helpers ---

    protected function devotee(Request $request): Devotee
    {
        return $request->user();
    }

    /** Somebody else's drive reads as missing rather than forbidden. */
    protected function ownedBy(SevaDrive $drive, Devotee $devotee): void
    {
        if (! $drive->isOrganisedBy($devotee)) {
            throw new NotFoundHttpException();
        }
    }

    protected function visibleTo(SevaDrive $drive, Devotee $devotee): void
    {
        if (! $drive->status->isPublic() && ! $drive->isOrganisedBy($devotee)) {
            throw new NotFoundHttpException();
        }
    }

    protected function canAddAfter(SevaDrive $drive): bool
    {
        return in_array($drive->status, [SevaDriveStatus::Approved, SevaDriveStatus::Completed, SevaDriveStatus::Verified], true)
            && $drive->starts_at->isPast();
    }

    /** @return array<string, mixed> */
    protected function detailRules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'title' => [$required, 'string', 'min:8', 'max:120'],
            'cause' => [$required, Rule::enum(SevaCause::class)],
            'temple' => ['nullable', 'string', 'max:191'],
            'place_name' => [$required, 'string', 'max:191'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:80'],
            'pincode' => ['nullable', 'string', 'regex:/^[1-9][0-9]{5}$/'],
            'district' => ['nullable', 'string', 'max:80'],
            'state_id' => ['nullable', 'integer', 'exists:states,id'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'meeting_point' => ['nullable', 'string', 'max:255'],
            'problem' => [$required, 'string', 'min:20', 'max:3000'],
            'plan' => [$required, 'string', 'min:20', 'max:3000'],
            'what_to_bring' => ['nullable', 'string', 'max:1000'],
            'starts_at' => [$required, 'date', 'after:now'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'volunteers_needed' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'contact_phone' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+ ()-]{6,20}$/'],
            // name@bank — what every UPI app calls a VPA.
            'upi_id' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9.\-_]{2,256}@[A-Za-z]{2,64}$/'],
            'upi_name' => ['nullable', 'string', 'max:80'],
            'donation_goal' => ['nullable', 'integer', 'min:100', 'max:10000000'],
            'donation_purpose' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, mixed> */
    protected function mediaRules(bool $required): array
    {
        return [
            // At least one photograph, or a video, when raising a drive: a
            // drive nobody can see the place of is a drive nobody can judge.
            'photos' => [$required ? 'required_without_all:video,video_url' : 'nullable', 'array', 'max:'.SevaDriveMedia::MAX_PER_STAGE],
            'photos.*' => ['image', 'mimes:'.UploadRules::mimesRuleFor('seva_photo'), 'max:'.UploadRules::maxKbFor('seva_photo')],
            'video' => ['nullable', 'file', 'mimes:'.UploadRules::mimesRuleFor('seva_video'), 'max:'.UploadRules::maxKbFor('seva_video')],
            'video_url' => ['nullable', 'url:https', 'max:255'],
            'caption' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @param array<string, mixed> $validated */
    protected function fillableFrom(array $validated): array
    {
        return collect($validated)->except(['temple', 'photos', 'video', 'video_url', 'caption'])->all();
    }

    protected function templeId(?string $slug): ?int
    {
        if (blank($slug)) {
            return null;
        }

        $temple = Temple::query()->where('slug', $slug)->where('status', TempleStatus::Published)->first();

        if ($temple === null) {
            throw ValidationException::withMessages(['temple' => 'No published temple has that address.']);
        }

        return $temple->getKey();
    }

    /** @return \Illuminate\Support\Collection<int, SevaDriveMedia> */
    protected function storeMedia(Request $request, SevaDrive $drive, Devotee $devotee, string $stage)
    {
        $existing = $drive->media()->where('stage', $stage)->count();
        $incoming = count($request->file('photos', []))
            + ($request->hasFile('video') ? 1 : 0)
            + ($request->filled('video_url') ? 1 : 0);

        if ($existing + $incoming > SevaDriveMedia::MAX_PER_STAGE) {
            throw ValidationException::withMessages(['photos' => 'A drive keeps up to '.SevaDriveMedia::MAX_PER_STAGE.' photographs and videos '.$stage.'. Remove one to add another.']);
        }

        $disk = config('filesystems.media');
        $directory = 'seva-drives/'.$drive->getKey().'/'.$stage;
        $caption = $request->input('caption');
        $added = collect();

        $make = fn (array $attributes): SevaDriveMedia => $drive->media()->create([
            'devotee_id' => $devotee->getKey(),
            'stage' => $stage,
            'caption' => $caption,
            ...$attributes,
        ]);

        /** @var UploadedFile $photo */
        foreach ($request->file('photos', []) as $photo) {
            $added->push($make([
                'type' => SevaDriveMedia::TYPE_PHOTO,
                'disk' => $disk,
                'path' => $photo->store($directory, ['disk' => $disk]),
            ]));
        }

        if ($request->hasFile('video')) {
            $added->push($make([
                'type' => SevaDriveMedia::TYPE_VIDEO,
                'disk' => $disk,
                'path' => $request->file('video')->store($directory, ['disk' => $disk]),
            ]));
        }

        if ($request->filled('video_url')) {
            $added->push($make([
                'type' => SevaDriveMedia::TYPE_VIDEO,
                'video_url' => $request->input('video_url'),
            ]));
        }

        return $added;
    }

    protected function withListing(Builder $query): Builder
    {
        return $query
            ->with(['organiser:id,name,avatar_disk,avatar_path', 'temple:id,slug,name', 'state:id,name', 'media'])
            ->withCount('volunteers')
            ->withSum('volunteers', 'party_size')
            ->withSum(['donations as donations_raised' => fn (Builder $q) => $q->whereNotNull('confirmed_at')], 'amount')
            ->withCount(['donations as donors_count' => fn (Builder $q) => $q->whereNotNull('confirmed_at')]);
    }

    protected function loadDetail(SevaDrive $drive): SevaDrive
    {
        return $drive->load(['organiser:id,name,avatar_disk,avatar_path', 'temple:id,slug,name', 'state:id,name', 'media', 'volunteers:id,seva_drive_id,devotee_id,party_size'])
            ->loadCount(['volunteers', 'donations as donors_count' => fn (Builder $q) => $q->whereNotNull('confirmed_at')])
            ->loadSum('volunteers', 'party_size')
            ->loadSum(['donations as donations_raised' => fn (Builder $q) => $q->whereNotNull('confirmed_at')], 'amount');
    }

    /** @return array<string, mixed> */
    protected function donationRow(SevaDriveDonation $donation, bool $forOrganiser): array
    {
        return [
            'id' => $donation->id,
            'amount' => $donation->amount,
            'donor' => $forOrganiser && ! $donation->is_anonymous ? $donation->devotee?->name : $donation->donorName(),
            'is_anonymous' => $donation->is_anonymous,
            'upi_ref' => $forOrganiser ? $donation->upi_ref : null,
            'message' => $donation->message,
            'confirmed' => $donation->isConfirmed(),
            'created_at' => $donation->created_at?->toIso8601String(),
        ];
    }
}
