<?php

namespace App\Http\Resources\V1;

use App\Models\Temple;
use App\Models\TempleCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The passport itself: what the devotee has collected.
 *
 * Circuit progress is the part that makes it a passport rather than a list.
 * "6 of 12 Jyotirlingas" is a thing to finish; "6 temples visited" is not.
 *
 * @mixin \App\Models\Devotee
 */
class PassportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $verifiedTempleIds = $this->resource->visits()
            ->verified()
            ->distinct()
            ->pluck('temple_id');

        return [
            'stamps' => $verifiedTempleIds->count(),
            'temples_visited' => $this->resource->templesVisitedCount(),
            'visits_recorded' => $this->resource->visits()->count(),
            'photos' => $this->resource->photos()->count(),
            'memories' => $this->resource->memories()->count(),
            'states_covered' => $this->statesCovered(),
            'first_visit_on' => $this->resource->visits()->min('visited_on'),
            'latest_visit_on' => $this->resource->visits()->max('visited_on'),

            'circuits' => $this->circuitProgress($verifiedTempleIds->all()),
        ];
    }

    /** Distinct states with at least one recorded visit. */
    protected function statesCovered(): int
    {
        return Temple::query()
            ->whereIn('id', $this->resource->visits()->select('temple_id'))
            ->whereNotNull('state_id')
            ->distinct()
            ->count('state_id');
    }

    /**
     * How far through each recognised circuit the devotee is.
     *
     * Counted against the temples actually recorded in that circuit, not
     * against `expected_count`: telling someone they are 6 of 12 through the
     * Jyotirlingas when only 8 are in the database would have them hunting
     * for temples the app cannot show them. Both numbers are returned so the
     * app can say "6 of 8 recorded, 12 in all".
     *
     * @param  array<int, int>  $verifiedTempleIds
     * @return array<int, array<string, mixed>>
     */
    protected function circuitProgress(array $verifiedTempleIds): array
    {
        return TempleCategory::query()
            ->where('kind', 'circuit')
            ->where('is_active', true)
            ->withCount([
                'temples as recorded_count' => fn ($q) => $q->published(),
                'temples as collected_count' => fn ($q) => $q->published()
                    ->whereIn('temples.id', $verifiedTempleIds ?: [0]),
            ])
            ->orderBy('sort_order')
            ->get()
            ->map(fn (TempleCategory $circuit): array => [
                'slug' => $circuit->slug,
                'name' => $circuit->name,
                'collected' => (int) $circuit->collected_count,
                'recorded' => (int) $circuit->recorded_count,
                'total' => $circuit->expected_count !== null
                    ? (int) $circuit->expected_count
                    : (int) $circuit->recorded_count,
                'is_complete' => $circuit->expected_count !== null
                    && $circuit->collected_count >= $circuit->expected_count,
            ])
            ->values()
            ->all();
    }
}
