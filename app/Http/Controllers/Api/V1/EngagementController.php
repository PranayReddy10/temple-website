<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\TempleSummaryResource;
use App\Models\Temple;
use App\Models\TempleFollow;
use App\Models\TempleLike;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Likes and follows: the two lightest things a devotee can do to a temple.
 *
 * Both are idempotent puts and deletes, because the app records the tap at
 * once and sends it when it can, and a tap that arrives twice must not
 * count twice. Only published temples: a draft is not something a devotee
 * should be able to discover, let alone follow.
 */
class EngagementController extends Controller
{
    // --- Likes ---

    public function likes(Request $request): JsonResponse
    {
        $temples = $request->user()->likedTemples()
            ->published()
            ->with(['deity', 'state', 'district', 'primaryPhoto'])
            ->orderByPivot('created_at', 'desc')
            ->paginate(50);

        return TempleSummaryResource::collection($temples)->response();
    }

    public function like(Request $request, Temple $temple): JsonResponse
    {
        abort_unless($temple->status->isPublic(), 404);

        TempleLike::query()->firstOrCreate(['devotee_id' => $request->user()->getKey(), 'temple_id' => $temple->getKey()]);

        return response()->json(['data' => $this->state($request, $temple)], 201);
    }

    public function unlike(Request $request, Temple $temple): JsonResponse
    {
        TempleLike::query()->where('devotee_id', $request->user()->getKey())->where('temple_id', $temple->getKey())->delete();

        return response()->json(['data' => $this->state($request, $temple)]);
    }

    // --- Follows ---

    /** Followed temples with what each one may send. */
    public function follows(Request $request): JsonResponse
    {
        $follows = $request->user()->follows()
            ->with(['temple' => fn ($q) => $q->with(['deity', 'state', 'district', 'primaryPhoto'])])
            ->whereHas('temple', fn ($q) => $q->published())
            ->latest()
            ->get();

        return response()->json(['data' => $follows->map(fn (TempleFollow $f): array => [
            'temple' => (new TempleSummaryResource($f->temple))->resolve($request),
            'notify_festivals' => $f->notify_festivals,
            'notify_events' => $f->notify_events,
            'followed_at' => $f->created_at?->toIso8601String(),
        ])->values()->all()]);
    }

    public function follow(Request $request, Temple $temple): JsonResponse
    {
        abort_unless($temple->status->isPublic(), 404);

        $validated = $request->validate([
            'notify_festivals' => ['nullable', 'boolean'],
            'notify_events' => ['nullable', 'boolean'],
        ]);

        $follow = TempleFollow::query()->firstOrNew(['devotee_id' => $request->user()->getKey(), 'temple_id' => $temple->getKey()]);
        // A first follow starts with reminders on; a later call changes only
        // what it names, so re-following does not reset someone's choices.
        if (array_key_exists('notify_festivals', $validated) && $validated['notify_festivals'] !== null) {
            $follow->notify_festivals = (bool) $validated['notify_festivals'];
        }
        if (array_key_exists('notify_events', $validated) && $validated['notify_events'] !== null) {
            $follow->notify_events = (bool) $validated['notify_events'];
        }
        $created = ! $follow->exists;
        $follow->save();

        return response()->json(['data' => $this->state($request, $temple)], $created ? 201 : 200);
    }

    public function unfollow(Request $request, Temple $temple): JsonResponse
    {
        TempleFollow::query()->where('devotee_id', $request->user()->getKey())->where('temple_id', $temple->getKey())->delete();

        return response()->json(['data' => $this->state($request, $temple)]);
    }

    /**
     * Counts and where the caller stands: what the temple page shows after
     * a tap, without a second request for the whole temple.
     *
     * @return array<string, mixed>
     */
    public static function state(Request $request, Temple $temple): array
    {
        $devotee = $request->user('devotee');
        $follow = $devotee === null ? null : TempleFollow::query()->where('devotee_id', $devotee->getKey())->where('temple_id', $temple->getKey())->first();

        $mine = $devotee === null ? null : \App\Models\TempleReview::query()
            ->where('devotee_id', $devotee->getKey())
            ->where('temple_id', $temple->getKey())
            ->with(['devotee:id,name,avatar_path,avatar_disk,home_state_id', 'devotee.homeState:id,name'])
            ->first();

        return [
            'likes_count' => $temple->likes()->count(),
            'follows_count' => $temple->follows()->count(),
            // The summary per dimension, and the newest published accounts
            // to show on the page itself. Served with every like and follow
            // too, so a tap never blanks the reviews section.
            'reviews' => \App\Models\TempleReview::summaryFor($temple) + [
                'latest' => \App\Http\Resources\V1\ReviewResource::collection(
                    $temple->reviews()->approved()
                        ->with(['devotee:id,name,avatar_path,avatar_disk,home_state_id', 'devotee.homeState:id,name'])
                        ->limit(3)->get()
                )->resolve($request),
            ],
            'viewer' => $devotee === null ? null : [
                // Their own account of this temple, whatever its status, so
                // the page offers to edit it rather than write a second.
                'my_review' => $mine === null ? null : (new \App\Http\Resources\V1\ReviewResource($mine))->resolve($request),
                'liked' => TempleLike::query()->where('devotee_id', $devotee->getKey())->where('temple_id', $temple->getKey())->exists(),
                'following' => $follow !== null,
                'notify_festivals' => $follow?->notify_festivals ?? false,
                'notify_events' => $follow?->notify_events ?? false,
                'saved' => $devotee->savedTemples()->whereKey($temple->getKey())->exists(),
            ],
        ];
    }
}
