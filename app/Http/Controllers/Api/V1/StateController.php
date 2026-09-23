<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TempleStatus;
use App\Http\Controllers\Controller;
use App\Models\State;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $withDistricts = $request->boolean('with_districts');

        $states = State::query()
            ->withCount(['temples' => fn ($q) => $q->where('status', TempleStatus::Published)])
            ->when($withDistricts, fn ($q) => $q->with('districts'))
            ->orderBy('name')
            ->get()
            ->map(fn (State $state): array => array_filter([
                // Needed by the app to set a devotee's home_state_id.
                'id' => $state->id,
                'slug' => $state->slug,
                'name' => $state->name,
                'code' => $state->code,
                'type' => $state->type,
                'temple_count' => $state->temples_count,
                'districts' => $withDistricts
                    ? $state->districts->map(fn ($d) => ['slug' => $d->slug, 'name' => $d->name])->values()
                    : null,
            ], fn ($value) => $value !== null));

        return response()->json(['data' => $states]);
    }
}
