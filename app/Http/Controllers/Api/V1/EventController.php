<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\EventType;
use App\Enums\TempleStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\EventResource;
use App\Models\TempleEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class EventController extends Controller
{
    /**
     * Upcoming events across every published temple.
     *
     * Published-only is applied here rather than exposed as a filter, and the
     * temple must be published too: an event on a draft temple would leak the
     * existence of a record that is not ready. `type=bhajan` with lat/lng
     * gives the bhajan gatherings near a devotee.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'temple' => ['nullable', 'string', 'max:120'],
            'type' => ['nullable', 'string', Rule::enum(EventType::class)],
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
            'radius' => ['nullable', 'numeric', 'min:1', 'max:500'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $events = TempleEvent::query()
            ->published()
            ->upcoming()
            ->whereHas('temple', function ($q) use ($validated) {
                $q->where('status', TempleStatus::Published);
                if (isset($validated['lat'], $validated['lng'])) {
                    $q->withinBoundingBox((float) $validated['lat'], (float) $validated['lng'], (float) ($validated['radius'] ?? 50));
                }
            })
            ->with('temple')
            ->when(
                isset($validated['temple']),
                fn ($q) => $q->whereHas('temple', fn ($t) => $t->where('slug', $validated['temple'])),
            )
            ->when(isset($validated['type']), fn ($q) => $q->where('type', $validated['type']))
            ->orderBy('starts_on')
            ->paginate($validated['per_page'] ?? 20)
            ->withQueryString();

        return EventResource::collection($events);
    }

    public function show(Request $request, int $event): JsonResponse
    {
        $record = TempleEvent::query()
            ->published()
            ->whereHas('temple', fn ($q) => $q->where('status', TempleStatus::Published))
            ->with('temple')
            ->find($event) ?? throw new NotFoundHttpException;

        return response()->json(['data' => (new EventResource($record))->resolve($request)]);
    }
}
