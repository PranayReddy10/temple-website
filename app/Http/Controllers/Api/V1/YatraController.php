<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TempleStatus;
use App\Enums\YatraStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\YatraResource;
use App\Models\Temple;
use App\Models\Yatra;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The Yatra planner: several temples, over some days, in an order.
 *
 * Deliberately a plain itinerary rather than a routing engine. Ordering
 * temples by road distance needs a maps provider, a budget and an internet
 * connection none of which a devotee planning on a train has; what they do
 * have is a list they can reorder themselves, and that works offline.
 */
class YatraController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $yatras = $request->user()
            ->yatras()
            ->with(['stops.temple:id,slug,name,city'])
            ->paginate(min(50, max(1, (int) $request->integer('per_page', 20))));

        return YatraResource::collection($yatras);
    }

    public function show(Request $request, Yatra $yatra): YatraResource
    {
        $this->assertOwned($request, $yatra);

        return new YatraResource($yatra->load('stops.temple:id,slug,name,city'));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules());

        $yatra = new Yatra($validated);
        $yatra->devotee_id = $request->user()->getKey();
        $yatra->save();

        return (new YatraResource($yatra->load('stops.temple:id,slug,name,city')))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, Yatra $yatra): YatraResource
    {
        $this->assertOwned($request, $yatra);

        $yatra->update($request->validate($this->rules(updating: true)));

        return new YatraResource($yatra->load('stops.temple:id,slug,name,city'));
    }

    public function destroy(Request $request, Yatra $yatra): JsonResponse
    {
        $this->assertOwned($request, $yatra);

        $yatra->delete();

        return response()->json(['data' => ['message' => 'Trip removed.']]);
    }

    /**
     * Add a temple to the trip.
     *
     * Idempotent: the table forbids the same temple twice, and a device that
     * retried a request on a flaky connection should not get a 500 for it.
     */
    public function addStop(Request $request, Yatra $yatra, Temple $temple): JsonResponse
    {
        $this->assertOwned($request, $yatra);

        if ($temple->status !== TempleStatus::Published) {
            throw new NotFoundHttpException();
        }

        $validated = $request->validate([
            'day_number' => ['nullable', 'integer', 'min:1', 'max:365'],
            'planned_on' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $day = (int) ($validated['day_number'] ?? 1);

        $yatra->stops()->updateOrCreate(
            ['temple_id' => $temple->getKey()],
            [
                'day_number' => $day,
                // Appended to the end of that day rather than colliding at 0,
                // so a list added one temple at a time keeps its order.
                'sort_order' => $yatra->stops()->where('day_number', $day)->max('sort_order') + 1,
                'planned_on' => $validated['planned_on'] ?? null,
                'note' => $validated['note'] ?? null,
            ],
        );

        return (new YatraResource($yatra->fresh()->load('stops.temple:id,slug,name,city')))
            ->response()
            ->setStatusCode(201);
    }

    public function removeStop(Request $request, Yatra $yatra, Temple $temple): JsonResponse
    {
        $this->assertOwned($request, $yatra);

        $yatra->stops()->where('temple_id', $temple->getKey())->delete();

        return response()->json(['data' => ['message' => 'Temple removed from the trip.']]);
    }

    /** @return array<string, array<int, mixed>> */
    protected function rules(bool $updating = false): array
    {
        return [
            'title' => [$updating ? 'sometimes' : 'required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:5000'],
            'status' => ['nullable', Rule::enum(YatraStatus::class)],
            'starts_on' => ['nullable', 'date'],
            // A trip that ends before it starts is a typo, and catching it
            // here saves a date picker from rendering nonsense.
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'party_size' => ['nullable', 'integer', 'min:1', 'max:500'],
            'is_public' => ['nullable', 'boolean'],
        ];
    }

    protected function assertOwned(Request $request, Yatra $yatra): void
    {
        if ($yatra->devotee_id !== $request->user()?->getKey()) {
            throw new NotFoundHttpException();
        }
    }
}
