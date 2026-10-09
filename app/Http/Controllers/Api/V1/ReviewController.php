<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ReviewStatus;
use App\Enums\TempleStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ReviewResource;
use App\Models\DevoteeVisit;
use App\Models\Setting;
use App\Models\Temple;
use App\Models\TempleReview;
use App\Support\DevotionalClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Accounts of visits: reading a temple's, writing one's own.
 *
 * By default nothing here is published on arrival: a review reaches other
 * devotees once a moderator has read it, the same way a photo does. The
 * "Reviews need approval" setting turns that off, and a review is then
 * published as it is sent. Either way the author sees theirs, and where it
 * stands, straight away.
 */
class ReviewController extends Controller
{
    /** A temple's published accounts, newest first, with the summary. */
    public function index(Request $request, Temple $temple): JsonResponse
    {
        if ($temple->status !== TempleStatus::Published) {
            throw new NotFoundHttpException();
        }

        $page = $temple->reviews()
            ->approved()
            ->with(['devotee:id,name,avatar_path,avatar_disk,home_state_id', 'devotee.homeState:id,name'])
            ->paginate(20);

        return response()->json([
            'data' => ReviewResource::collection($page->getCollection())->resolve($request),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'summary' => TempleReview::summaryFor($temple),
            ],
        ]);
    }

    public function mine(Request $request): AnonymousResourceCollection
    {
        return ReviewResource::collection(
            $request->user()->reviews()->with(['temple:id,slug,name,city,deity_id', 'temple.deity:id,slug', 'temple.primaryPhoto', 'devotee:id,name,avatar_path,avatar_disk,home_state_id'])->paginate(50)
        );
    }

    public function store(Request $request, Temple $temple): JsonResponse
    {
        if ($temple->status !== TempleStatus::Published) {
            throw new NotFoundHttpException();
        }

        $validated = $request->validate(self::rules());
        [$review, $created] = self::saveFor($request->user(), $temple, $validated);

        return (new ReviewResource($review->load(['temple:id,slug,name,city', 'temple.primaryPhoto', 'devotee:id,name,avatar_path,avatar_disk,home_state_id'])))
            ->response()
            ->setStatusCode($created ? 201 : 200);
    }

    /** On unless an admin has switched it off under Settings → Features. */
    /** @return array<string, array<int, string>> what a devotee may say about a visit */
    public static function rules(): array
    {
        $rating = ['nullable', 'integer', 'min:1', 'max:5'];

        return [
            'visited_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.DevotionalClock::latestDateAnywhere()],
            'visit_id' => ['nullable', 'integer'],
            'queue_rating' => $rating,
            'cleanliness_rating' => $rating,
            'facilities_rating' => $rating,
            'accessibility_rating' => $rating,
            'accuracy_rating' => $rating,
            'wait_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'body' => ['nullable', 'string', 'max:3000'],
        ];
    }

    /**
     * The devotee's account of a temple, new or replacing their earlier one
     * (one per devotee and temple), awaiting moderation when that is on.
     * Shared by the app's API and the website.
     *
     * @param  array<string, mixed>  $validated
     * @return array{0: TempleReview, 1: bool} the review, and whether it is new
     */
    public static function saveFor(\App\Models\Devotee $devotee, Temple $temple, array $validated): array
    {
        $said = collect($validated)->only([...array_keys(TempleReview::DIMENSIONS), 'wait_minutes', 'body'])->filter(fn ($v) => $v !== null && $v !== '');

        if ($said->isEmpty()) {
            throw ValidationException::withMessages(['body' => 'Say something about the visit: a rating, or a few words.']);
        }

        $visit = null;
        if (! empty($validated['visit_id'])) {
            $visit = DevoteeVisit::query()->whereKey($validated['visit_id'])
                ->where('devotee_id', $devotee->getKey())
                ->where('temple_id', $temple->getKey())
                ->first() ?? throw new NotFoundHttpException();
        }

        $visitedOn = $validated['visited_on'] ?? $visit?->visited_on?->toDateString() ?? DevotionalClock::now()->toDateString();

        // One account per temple per devotee: writing again edits it (and,
        // while reviews need approval, sends it back for review), whatever day it is about, rather than
        // stacking a second one under the same name.
        $review = TempleReview::query()
            ->where('devotee_id', $devotee->getKey())
            ->where('temple_id', $temple->getKey())
            ->first() ?? new TempleReview([
                'devotee_id' => $devotee->getKey(),
                'temple_id' => $temple->getKey(),
            ]);
        $created = ! $review->exists;
        $review->visited_on = $visitedOn;

        // An edit is the whole account again: a rating left out now is a
        // rating taken back, not one kept from before.
        foreach ([...array_keys(TempleReview::DIMENSIONS), 'wait_minutes', 'body'] as $field) {
            $review->{$field} = $validated[$field] ?? null;
        }
        $review->devotee_visit_id = $visit?->getKey() ?? $review->devotee_visit_id;
        $review->status = self::requiresApproval() ? ReviewStatus::Pending : ReviewStatus::Approved;
        $review->moderated_at = null;
        $review->moderated_by = null;
        $review->moderation_note = null;
        $review->save();

        return [$review, $created];
    }

    public static function requiresApproval(): bool
    {
        return (bool) Setting::get('reviews_require_approval', '1');
    }

    public function destroy(Request $request, TempleReview $review): JsonResponse
    {
        if ($review->devotee_id !== $request->user()->getKey()) {
            throw new NotFoundHttpException();
        }

        $review->delete();

        return response()->json(['data' => ['message' => 'Removed.']]);
    }
}
