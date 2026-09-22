<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TempleStatus;
use App\Enums\VerificationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\TempleIndexRequest;
use App\Http\Resources\V1\TempleDetailResource;
use App\Http\Resources\V1\TempleSummaryResource;
use App\Models\Temple;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class TempleController extends Controller
{
    public function index(TempleIndexRequest $request): AnonymousResourceCollection
    {
        $query = Temple::query()
            // Published-only is applied here, not exposed as a filter. A draft
            // temple must be unreachable through this endpoint by any input.
            ->published()
            ->with(['deity', 'state', 'district', 'primaryPhoto']);

        $this->applyFilters($query, $request);
        $this->applySort($query, $request);

        $temples = $query
            ->paginate($request->perPage())
            ->withQueryString();

        return TempleSummaryResource::collection($temples);
    }

    public function show(Temple $temple): TempleDetailResource
    {
        // Route-model binding resolves by slug without regard to status, so an
        // unpublished temple must be rejected explicitly. Returning 404 rather
        // than 403 avoids confirming that a draft with this slug exists.
        if ($temple->status !== TempleStatus::Published) {
            throw new NotFoundHttpException();
        }

        $temple->load([
            'deity', 'state', 'district', 'categories', 'aliases',
            'timings',
            'pujas' => fn ($q) => $q->published(),
            'photos' => fn ($q) => $q->published(),
            'closures' => fn ($q) => $q->upcoming(),
            'events' => fn ($q) => $q->published()->upcoming(),
            'facilities',
        ]);

        return new TempleDetailResource($temple);
    }

    protected function applyFilters(Builder $query, TempleIndexRequest $request): void
    {
        $query
            ->when($request->filled('q'), fn (Builder $q) => $q->search($request->string('q')->toString()))
            ->when($request->filled('deity'), fn (Builder $q) => $q->whereHas(
                'deity',
                fn (Builder $d) => $d->where('slug', $request->input('deity')),
            ))
            ->when($request->filled('category'), fn (Builder $q) => $q->whereHas(
                'categories',
                fn (Builder $c) => $c->where('slug', $request->input('category')),
            ))
            ->when($request->filled('state'), fn (Builder $q) => $q->whereHas(
                'state',
                fn (Builder $s) => $s->where('slug', $request->input('state')),
            ))
            ->when($request->filled('district'), fn (Builder $q) => $q->whereHas(
                'district',
                fn (Builder $d) => $d->where('slug', $request->input('district')),
            ))
            ->when($request->boolean('verified'), fn (Builder $q) => $q->whereIn('verification_status', [
                VerificationStatus::Verified->value,
                VerificationStatus::Official->value,
            ]));

        if ($request->hasCoordinates()) {
            $lat = (float) $request->input('lat');
            $lng = (float) $request->input('lng');

            // Bounding box first so the (latitude, longitude) index does the
            // heavy filtering; the trigonometry then runs over few rows.
            $query
                ->withinBoundingBox($lat, $lng, $request->radiusKm())
                ->withDistanceFrom($lat, $lng);
        }
    }

    protected function applySort(Builder $query, TempleIndexRequest $request): void
    {
        $sort = $request->input('sort');

        // withDistanceFrom already ordered by distance. Re-ordering here would
        // silently discard the point of a nearby search.
        if ($request->hasCoordinates() && in_array($sort, [null, 'distance'], true)) {
            return;
        }

        match ($sort) {
            '-name' => $query->orderBy('name', 'desc'),
            'recent' => $query->orderByDesc('published_at'),
            default => $query->orderBy('name'),
        };
    }
}
