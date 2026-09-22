<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TempleStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\EventResource;
use App\Models\TempleEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EventController extends Controller
{
    /**
     * Upcoming events across every published temple.
     *
     * Published-only is applied here rather than exposed as a filter, and the
     * temple must be published too: an event on a draft temple would leak the
     * existence of a record that is not ready.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'temple' => ['nullable', 'string', 'max:120'],
            'type' => ['nullable', 'string', 'in:festival,program,puja,announcement'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $events = TempleEvent::query()
            ->published()
            ->upcoming()
            ->whereHas('temple', fn ($q) => $q->where('status', TempleStatus::Published))
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
}
