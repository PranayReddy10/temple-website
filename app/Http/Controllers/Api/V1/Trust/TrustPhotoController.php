<?php

namespace App\Http\Controllers\Api\V1\Trust;

use App\Enums\PhotoCategory;
use App\Http\Controllers\Api\V1\Trust\Concerns\ScopesToTrustTemples;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\PhotoResource;
use App\Models\TemplePhoto;
use App\Support\UploadRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The temple's own photographs. Resizing, the single lead image and file
 * clean-up are TemplePhotoObserver's, so an upload here gets the same
 * medium and thumbnail sizes as one from the portal.
 *
 * Photos devotees shared are listed but not editable: they are the
 * devotee's, and the portal's objection route is the way to raise them.
 */
class TrustPhotoController extends Controller
{
    use ScopesToTrustTemples;

    public function index(Request $request, int $temple): JsonResponse
    {
        $rows = $this->managedTemple($request, $temple)->photos()
            ->orderByDesc('is_primary')
            ->orderBy('sort_order')
            ->latest('id')
            ->limit(300)
            ->get();

        return response()->json(['data' => $rows->map(fn (TemplePhoto $p): array => $this->present($p, $request))->values()]);
    }

    public function store(Request $request, int $temple): JsonResponse
    {
        $record = $this->managedTemple($request, $temple);

        $validated = $request->validate([
            'photo' => ['required', 'image', 'mimes:'.UploadRules::mimesRuleFor('temple_photo'), 'max:'.UploadRules::maxKbFor('temple_photo')],
            'category' => ['required', Rule::enum(PhotoCategory::class)],
            'caption' => ['nullable', 'string', 'max:255'],
            'credit' => ['nullable', 'string', 'max:255'],
            'is_primary' => ['nullable', 'boolean'],
            'is_published' => ['nullable', 'boolean'],
        ]);

        $disk = config('filesystems.media');

        $photo = $record->photos()->create([
            'disk' => $disk,
            'path' => $request->file('photo')->store('temples/'.$record->getKey(), ['disk' => $disk]),
            'category' => $validated['category'],
            'caption' => $validated['caption'] ?? null,
            'credit' => $validated['credit'] ?? $record->name,
            'is_primary' => (bool) ($validated['is_primary'] ?? false),
            'is_published' => (bool) ($validated['is_published'] ?? true),
        ]);

        return response()->json(['data' => $this->present($photo->refresh(), $request)], 201);
    }

    public function update(Request $request, int $temple, int $photo): JsonResponse
    {
        $row = $this->ownPhoto($request, $temple, $photo);

        $validated = $request->validate([
            'category' => ['sometimes', 'required', Rule::enum(PhotoCategory::class)],
            'caption' => ['sometimes', 'nullable', 'string', 'max:255'],
            'credit' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_primary' => ['sometimes', 'boolean'],
            'is_published' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'between:0,1000'],
        ]);

        $row->fill($validated)->save();

        return response()->json(['data' => $this->present($row->refresh(), $request)]);
    }

    public function destroy(Request $request, int $temple, int $photo): JsonResponse
    {
        $this->ownPhoto($request, $temple, $photo)->delete();

        return response()->json(['data' => ['message' => 'Photo removed.']]);
    }

    protected function ownPhoto(Request $request, int $temple, int $photo): TemplePhoto
    {
        $row = $this->managedTemple($request, $temple)->photos()->findOrFail($photo);

        abort_if($row->isDevoteePhoto(), 403, 'This photo was shared by a devotee. Raise an objection from the temple portal instead.');

        return $row;
    }

    /** @return array<string, mixed> */
    protected function present(TemplePhoto $photo, Request $request): array
    {
        return (new PhotoResource($photo))->resolve($request) + [
            'is_published' => (bool) $photo->is_published,
            'sort_order' => (int) $photo->sort_order,
        ];
    }
}
