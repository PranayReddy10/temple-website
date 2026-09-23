<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\DevoteeResource;
use App\Http\Resources\V1\TempleSummaryResource;
use App\Models\Devotee;
use App\Models\Temple;
use App\Support\UploadRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class DevoteeProfileController extends Controller
{
    public function show(Request $request): DevoteeResource
    {
        return new DevoteeResource($request->user()->load('homeState'));
    }

    public function update(Request $request): DevoteeResource
    {
        $devotee = $request->user();

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255', Rule::unique('devotees', 'email')->ignore($devotee->id)],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20', 'regex:/^\+?[0-9]{7,15}$/', Rule::unique('devotees', 'phone')->ignore($devotee->id)],
            'locale' => ['sometimes', 'string', 'in:en,te,hi'],
            'home_state_id' => ['sometimes', 'nullable', 'integer', 'exists:states,id'],
            'date_of_birth' => ['sometimes', 'nullable', 'date', 'before:today'],
        ]);

        // Changing an identifier invalidates the verification of that
        // identifier: a new number has not been confirmed just because the
        // old one was.
        if (array_key_exists('email', $validated) && $validated['email'] !== $devotee->email) {
            $devotee->email_verified_at = null;
        }

        if (array_key_exists('phone', $validated) && $validated['phone'] !== $devotee->phone) {
            $devotee->phone_verified_at = null;
        }

        $devotee->fill($validated)->save();

        return new DevoteeResource($devotee->load('homeState'));
    }

    /**
     * Their profile photo.
     *
     * avatar_path and avatar_disk have been on the devotees table since the
     * table existed, and the API has been returning avatar_url all along —
     * but nothing could ever write them, so the field was always null and
     * every profile picture in the app and the admin was an empty circle.
     *
     * Its own endpoint rather than a field on update(): the profile is a JSON
     * PATCH and a file is multipart, and a path is not something a client
     * should be able to send us as a string.
     */
    public function storeAvatar(Request $request): DevoteeResource
    {
        $request->validate([
            'avatar' => [
                'required',
                'image',
                'mimes:'.UploadRules::mimesRuleFor('devotee_avatar'),
                'max:'.UploadRules::maxKbFor('devotee_avatar'),
            ],
        ]);

        $devotee = $request->user();
        $disk = config('filesystems.media');

        $path = $request->file('avatar')->store('avatars/'.$devotee->getKey(), ['disk' => $disk]);

        $this->deleteStoredAvatar($devotee);

        $devotee->forceFill(['avatar_path' => $path, 'avatar_disk' => $disk])->save();

        return new DevoteeResource($devotee->load('homeState'));
    }

    public function destroyAvatar(Request $request): DevoteeResource
    {
        $devotee = $request->user();

        $this->deleteStoredAvatar($devotee);

        $devotee->forceFill(['avatar_path' => null, 'avatar_disk' => null])->save();

        return new DevoteeResource($devotee->load('homeState'));
    }

    /**
     * Removes the file the devotee is replacing or clearing.
     *
     * Failure here is deliberately not fatal: a file that has already gone,
     * or a disk that refuses the delete, must not stop somebody changing
     * their own picture. The row is the record of what is current.
     */
    protected function deleteStoredAvatar(Devotee $devotee): void
    {
        if (blank($devotee->avatar_path)) {
            return;
        }

        try {
            Storage::disk($devotee->avatar_disk ?? config('filesystems.media'))
                ->delete($devotee->avatar_path);
        } catch (\Throwable) {
            // Nothing to do: the new path is already recorded.
        }
    }

    public function savedTemples(Request $request): JsonResponse
    {
        $temples = $request->user()
            ->savedTemples()
            ->published()
            ->with(['deity', 'state', 'district', 'primaryPhoto'])
            ->orderByPivot('created_at', 'desc')
            ->paginate(20);

        return TempleSummaryResource::collection($temples)->response();
    }

    public function saveTemple(Request $request, Temple $temple): JsonResponse
    {
        // Only published temples can be saved: a draft is not something a
        // devotee should be able to discover, let alone bookmark.
        abort_unless($temple->status->isPublic(), 404);

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $request->user()->savedTemples()->syncWithoutDetaching([
            $temple->id => ['note' => $validated['note'] ?? null],
        ]);

        return response()->json([
            'data' => ['message' => 'Saved.', 'temple' => $temple->slug],
        ], 201);
    }

    public function forgetTemple(Request $request, Temple $temple): JsonResponse
    {
        $request->user()->savedTemples()->detach($temple->id);

        return response()->json(['data' => ['message' => 'Removed.']]);
    }
}
