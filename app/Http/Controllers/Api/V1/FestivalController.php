<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Festival;
use App\Support\DevotionalClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * India's festival and vrat calendar, for any range of dates (a month in
 * the calendar, the weeks ahead on the home screen). Public.
 */
class FestivalController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'kind' => ['nullable', Rule::in(array_keys(Festival::KINDS))],
            'major' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $from = $validated['from'] ?? DevotionalClock::now()->toDateString();
        $to = $validated['to'] ?? DevotionalClock::now()->addYear()->toDateString();

        $rows = Festival::query()
            ->published()
            ->between($from, $to)
            ->when($validated['kind'] ?? null, fn ($q, $k) => $q->where('kind', $k))
            ->when($request->boolean('major'), fn ($q) => $q->where('is_major', true))
            ->orderBy('starts_on')
            ->orderByDesc('is_major')
            ->limit($validated['limit'] ?? 400)
            ->get();

        return response()->json(['data' => $rows->map(fn (Festival $f): array => $f->toApiArray())->values()]);
    }
}
