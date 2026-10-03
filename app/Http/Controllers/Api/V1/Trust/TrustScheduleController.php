<?php

namespace App\Http\Controllers\Api\V1\Trust;

use App\Enums\TimingKind;
use App\Http\Controllers\Api\V1\Trust\Concerns\ScopesToTrustTemples;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ClosureResource;
use App\Http\Resources\V1\TimingResource;
use App\Models\TempleTiming;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Darshan timings and closures: when the doors are open, and when not.
 */
class TrustScheduleController extends Controller
{
    use ScopesToTrustTemples;

    public function timings(Request $request, int $temple): JsonResponse
    {
        $rows = TempleTiming::inReadingOrder($this->managedTemple($request, $temple)->timings()->get());

        return response()->json(['data' => TimingResource::collection($rows)->resolve($request)]);
    }

    /**
     * "Different timings on Sat & Sun": the every-day timings (or the ones
     * picked) become Mon–Fri, with a Sat & Sun copy of each to change.
     */
    public function splitWeekend(Request $request, int $temple): JsonResponse
    {
        $validated = $request->validate([
            'timing_ids' => ['nullable', 'array'],
            'timing_ids.*' => ['integer'],
        ]);
        $query = $this->managedTemple($request, $temple)->timings();
        if (! empty($validated['timing_ids'])) {
            $query->whereIn('id', $validated['timing_ids']);
        }
        $copies = TempleTiming::splitWeekend($query->get());
        if ($copies->isEmpty()) {
            return response()->json(['message' => 'There are no every-day timings to split. Add a timing, or pick its days.'], 422);
        }

        return response()->json(['data' => TimingResource::collection($copies)->resolve($request)], 201);
    }

    public function storeTiming(Request $request, int $temple): JsonResponse
    {
        $row = $this->managedTemple($request, $temple)->timings()->create($this->timingRules($request));

        return response()->json(['data' => (new TimingResource($row->refresh()))->resolve($request)], 201);
    }

    public function updateTiming(Request $request, int $temple, int $timing): JsonResponse
    {
        $row = $this->managedTemple($request, $temple)->timings()->findOrFail($timing);
        $row->fill($this->timingRules($request))->save();

        return response()->json(['data' => (new TimingResource($row->refresh()))->resolve($request)]);
    }

    public function destroyTiming(Request $request, int $temple, int $timing): JsonResponse
    {
        $this->managedTemple($request, $temple)->timings()->findOrFail($timing)->delete();

        return response()->json(['data' => ['message' => 'Timing removed.']]);
    }

    public function closures(Request $request, int $temple): JsonResponse
    {
        $rows = $this->managedTemple($request, $temple)->closures()->latest('starts_on')->limit(200)->get();

        return response()->json(['data' => ClosureResource::collection($rows)->resolve($request)]);
    }

    public function storeClosure(Request $request, int $temple): JsonResponse
    {
        $row = $this->managedTemple($request, $temple)->closures()->create($this->closureRules($request));

        return response()->json(['data' => (new ClosureResource($row->refresh()))->resolve($request)], 201);
    }

    public function updateClosure(Request $request, int $temple, int $closure): JsonResponse
    {
        $row = $this->managedTemple($request, $temple)->closures()->findOrFail($closure);
        $row->fill($this->closureRules($request))->save();

        return response()->json(['data' => (new ClosureResource($row->refresh()))->resolve($request)]);
    }

    public function destroyClosure(Request $request, int $temple, int $closure): JsonResponse
    {
        $this->managedTemple($request, $temple)->closures()->findOrFail($closure)->delete();

        return response()->json(['data' => ['message' => 'Closure removed.']]);
    }

    /** @return array<string, mixed> */
    protected function timingRules(Request $request): array
    {
        $validated = $request->validate([
            'kind' => ['required', Rule::enum(TimingKind::class)],
            'label' => ['nullable', 'string', 'max:120'],
            // The days it holds on; none (or all seven) is every day. An
            // older app sends one day_of_week instead (null is every day).
            'days' => ['nullable', 'array', 'max:7'],
            'days.*' => ['integer', 'between:0,6'],
            'day_of_week' => ['nullable', 'integer', 'between:0,6'],
            'opens_at' => ['nullable', 'date_format:H:i'],
            'closes_at' => ['nullable', 'date_format:H:i'],
            'notes' => ['nullable', 'string', 'max:500'],
            'sort_order' => ['nullable', 'integer', 'between:0,1000'],
        ]);

        $validated['sort_order'] = (int) ($validated['sort_order'] ?? 0);
        if ($request->has('days')) {
            $validated['days'] = TempleTiming::normaliseDays($validated['days'] ?? null);
            unset($validated['day_of_week']);
        } elseif ($request->has('day_of_week')) {
            $validated['days'] = $validated['day_of_week'] === null ? null : [(int) $validated['day_of_week']];
        }

        return $validated;
    }

    /** @return array<string, mixed> */
    protected function closureRules(Request $request): array
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'is_full_day' => ['required', 'boolean'],
            'opens_at' => ['nullable', 'date_format:H:i'],
            'closes_at' => ['nullable', 'date_format:H:i'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        // A full-day closure has no hours; keeping stale ones would render
        // as both "closed" and "open 6–12".
        if ($validated['is_full_day']) {
            $validated['opens_at'] = null;
            $validated['closes_at'] = null;
        }

        return $validated;
    }
}
