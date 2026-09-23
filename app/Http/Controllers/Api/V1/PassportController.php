<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CheckInMethod;
use App\Enums\TempleStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreVisitRequest;
use App\Http\Resources\V1\PassportResource;
use App\Http\Resources\V1\VisitResource;
use App\Models\DevoteeVisit;
use App\Models\Temple;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The Passport: recording visits and reading back the collection.
 *
 * Everything here is scoped to the signed-in devotee's own rows. That scoping
 * lives in the queries rather than in a check the next endpoint might forget:
 * a devotee's pilgrimage record is not another devotee's to read.
 */
class PassportController extends Controller
{
    /** The passport summary: stamps, counts and circuit progress. */
    public function show(Request $request): PassportResource
    {
        return new PassportResource($request->user());
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $visits = $request->user()
            ->visits()
            ->with(['temple:id,slug,name,city', 'photos'])
            ->paginate(min(50, max(1, (int) $request->integer('per_page', 20))));

        return VisitResource::collection($visits);
    }

    /**
     * Record a visit.
     *
     * A GPS or QR check-in verifies itself when it is close enough; a manual
     * one never does. Recording a pilgrimage from before the app existed is
     * the point of a passport, but a collection that cannot tell the two
     * apart is a collection nobody can trust.
     */
    public function store(StoreVisitRequest $request, Temple $temple): JsonResponse
    {
        if ($temple->status !== TempleStatus::Published) {
            throw new NotFoundHttpException();
        }

        $method = CheckInMethod::tryFrom((string) $request->input('method')) ?? CheckInMethod::Manual;

        $visit = new DevoteeVisit([
            'temple_id' => $temple->getKey(),
            'method' => $method,
            'visited_on' => $request->date('visited_on')?->toDateString() ?? now()->toDateString(),
            'visited_at' => $request->input('visited_at'),
            'latitude' => $request->input('latitude'),
            'longitude' => $request->input('longitude'),
            'note' => $request->input('note'),
            'is_public' => $request->boolean('is_public', true),
        ]);

        $visit->devotee_id = $request->user()->getKey();
        // Set before verification is decided: isWithinCheckInRadius reads it,
        // and recomputing later would use coordinates the temple may have had
        // corrected in the meantime.
        $visit->setRelation('temple', $temple);
        $visit->distance_metres = $visit->distanceFromTemple();

        if ($method->isSelfVerifying() && $visit->isWithinCheckInRadius()) {
            $visit->is_verified = true;
            $visit->verified_at = now();
        }

        $visit->save();

        // A trip that planned this temple is now one stop further along.
        $this->closeMatchingYatraStop($request, $visit);

        return (new VisitResource($visit->load('temple:id,slug,name,city')))
            ->response()
            ->setStatusCode(201);
    }

    public function destroy(Request $request, DevoteeVisit $visit): JsonResponse
    {
        $this->assertOwned($request, $visit);

        $visit->delete();

        return response()->json(['data' => ['message' => 'Visit removed.']]);
    }

    /**
     * Marks the planned stop for this temple as done.
     *
     * Only the devotee's own upcoming trips, and only a stop that is not
     * already closed — a second visit to the same temple should not reopen
     * and re-close a stop that a previous one completed.
     */
    protected function closeMatchingYatraStop(Request $request, DevoteeVisit $visit): void
    {
        $request->user()
            ->yatras()
            ->upcoming()
            ->get()
            ->each(fn ($yatra) => $yatra->stops()
                ->where('temple_id', $visit->temple_id)
                ->whereNull('devotee_visit_id')
                ->update(['devotee_visit_id' => $visit->getKey()]));
    }

    /**
     * 404, not 403.
     *
     * Telling someone their guess at an id belongs to a real visit is telling
     * them something about another devotee's pilgrimage.
     */
    protected function assertOwned(Request $request, DevoteeVisit $visit): void
    {
        if ($visit->devotee_id !== $request->user()?->getKey()) {
            throw new NotFoundHttpException();
        }
    }
}
