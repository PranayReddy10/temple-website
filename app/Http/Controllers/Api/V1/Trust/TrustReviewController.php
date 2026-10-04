<?php

namespace App\Http\Controllers\Api\V1\Trust;

use App\Http\Controllers\Api\V1\Trust\Concerns\ScopesToTrustTemples;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ReviewResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What devotees wrote about visiting, and the temple's replies. Only
 * published accounts: moderation stays with the editors.
 */
class TrustReviewController extends Controller
{
    use ScopesToTrustTemples;

    public function index(Request $request, int $temple): JsonResponse
    {
        $page = $this->managedTemple($request, $temple)->reviews()
            ->approved()
            ->with(['devotee:id,name,avatar_path,avatar_disk,home_state_id', 'devotee.homeState:id,name'])
            ->when($request->boolean('unanswered'), fn ($q) => $q->whereNull('temple_reply'))
            ->latest('id')
            ->paginate(30);

        return ReviewResource::collection($page)->response();
    }

    public function reply(Request $request, int $temple, int $review): JsonResponse
    {
        $row = $this->managedTemple($request, $temple)->reviews()->approved()->findOrFail($review);

        $validated = $request->validate(['reply' => ['required', 'string', 'max:1000']]);

        $row->update([
            'temple_reply' => $validated['reply'],
            'temple_replied_at' => now(),
            'temple_replied_by' => $this->trustUser($request)->getKey(),
        ]);

        return response()->json(['data' => (new ReviewResource($row->load('devotee')))->resolve($request)]);
    }
}
