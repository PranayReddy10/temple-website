<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TempleStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\VisitPhotoResource;
use App\Models\DevoteeVisit;
use App\Models\Temple;
use App\Models\VisitPhoto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Photo Stamp: the devotee uploads a photo from a visit, and gets back a
 * temple-themed memory card.
 *
 * The card itself is rendered by the app, which has the fonts and the layout
 * and can do it offline at the temple. What the backend owns is keeping the
 * original safe, keeping the two files distinct, and not showing either to
 * anyone else until a person has looked at it.
 */
class VisitPhotoController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $photos = $request->user()
            ->photos()
            ->with('temple:id,slug,name')
            ->paginate(min(50, max(1, (int) $request->integer('per_page', 20))));

        return VisitPhotoResource::collection($photos);
    }

    public function store(Request $request, Temple $temple): JsonResponse
    {
        if ($temple->status !== TempleStatus::Published) {
            throw new NotFoundHttpException();
        }

        $validated = $request->validate([
            'photo' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:12288'],
            // The generated card, uploaded alongside. Optional: a devotee who
            // wants their photo kept but not stamped is not doing it wrong.
            'stamp' => ['nullable', 'image', 'mimes:jpeg,png,webp', 'max:12288'],
            'visit_id' => ['nullable', 'integer'],
            'caption' => ['nullable', 'string', 'max:255'],
            'is_public' => ['nullable', 'boolean'],
        ]);

        $visit = $this->ownedVisit($request, $validated['visit_id'] ?? null, $temple);

        $disk = config('filesystems.media');
        $directory = 'visit-photos/'.$request->user()->getKey();

        $photo = new VisitPhoto([
            'temple_id' => $temple->getKey(),
            'devotee_visit_id' => $visit?->getKey(),
            'disk' => $disk,
            'original_path' => $request->file('photo')->store($directory, ['disk' => $disk]),
            'stamp_path' => $request->file('stamp')?->store($directory.'/stamps', ['disk' => $disk]),
            'caption' => $validated['caption'] ?? null,
            // Their intent is recorded now; it takes effect only once a
            // moderator has approved the image.
            'is_public' => $request->boolean('is_public'),
        ]);

        $photo->devotee_id = $request->user()->getKey();
        $photo->save();

        return (new VisitPhotoResource($photo->load('temple:id,slug,name')))
            ->response()
            ->setStatusCode(201);
    }

    public function destroy(Request $request, VisitPhoto $photo): JsonResponse
    {
        if ($photo->devotee_id !== $request->user()?->getKey()) {
            throw new NotFoundHttpException();
        }

        $photo->delete();

        return response()->json(['data' => ['message' => 'Photo removed.']]);
    }

    /**
     * The visit this photo belongs to, if one was named.
     *
     * Must be the devotee's own and must be for this temple: otherwise a
     * photo could be attached to a stranger's visit, or a photo of one temple
     * filed under another.
     */
    protected function ownedVisit(Request $request, ?int $visitId, Temple $temple): ?DevoteeVisit
    {
        if ($visitId === null) {
            return null;
        }

        $visit = DevoteeVisit::query()
            ->whereKey($visitId)
            ->where('devotee_id', $request->user()->getKey())
            ->where('temple_id', $temple->getKey())
            ->first();

        if ($visit === null) {
            throw new NotFoundHttpException();
        }

        return $visit;
    }
}
