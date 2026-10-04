<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\EventStatus;
use App\Enums\EventType;
use App\Enums\TempleStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\EventResource;
use App\Models\Temple;
use App\Models\TempleEvent;
use App\Support\DevotionalClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A bhajan gathering raised by a devotee at a temple, the way a seva drive
 * is raised: anyone signed in may propose one, it waits for the editors like
 * a temple team's own event does, and it is always free — "I'll join" is on,
 * tickets are never sold for it. Money at a temple is the temple's to take.
 */
class BhajanController extends Controller
{
    public function store(Request $request, Temple $temple): JsonResponse
    {
        if ($temple->status !== TempleStatus::Published) {
            throw new NotFoundHttpException;
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'min:3', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'group_name' => ['nullable', 'string', 'max:160'],
            'starts_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.DevotionalClock::now()->toDateString()],
            'starts_at' => ['nullable', 'date_format:H:i'],
            'ends_at' => ['nullable', 'date_format:H:i', 'after:starts_at'],
            'recurrence' => ['nullable', Rule::in(['none', 'weekly'])],
            'open_to_all' => ['nullable', 'boolean'],
            'songs' => ['nullable', 'string', 'max:5000'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ], [
            'starts_on.after_or_equal' => 'Pick today or a day ahead.',
            'ends_at.after' => 'The end must come after the start.',
        ]);

        $event = new TempleEvent([
            'temple_id' => $temple->getKey(),
            'type' => EventType::Bhajan,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'group_name' => $validated['group_name'] ?? null,
            'starts_on' => $validated['starts_on'],
            'ends_on' => null,
            'is_all_day' => empty($validated['starts_at']),
            'starts_at' => $validated['starts_at'] ?? null,
            'ends_at' => empty($validated['starts_at']) ? null : ($validated['ends_at'] ?? null),
            'recurrence' => $validated['recurrence'] ?? 'none',
            'open_to_all' => $validated['open_to_all'] ?? true,
            'songs' => $validated['songs'] ?? null,
            'capacity' => $validated['capacity'] ?? null,
            // Free, whatever was sent: a devotee cannot charge in a temple's name.
            'registration_enabled' => true,
            'ticket_price_paise' => 0,
            'max_people_per_registration' => 10,
            // The editors look first, as they do at a temple team's event.
            'status' => EventStatus::PendingReview,
        ]);
        $event->devotee_id = $request->user()->getKey();
        $event->save();

        return response()->json(['data' => $this->present($event->load(['temple', 'devotee']))], 201);
    }

    /** The bhajans this devotee raised, newest first, with where each stands. */
    public function mine(Request $request): JsonResponse
    {
        $rows = TempleEvent::query()
            ->where('devotee_id', $request->user()->getKey())
            ->with(['temple', 'devotee'])
            ->latest('id')
            ->limit(100)
            ->get();

        return response()->json(['data' => $rows->map(fn (TempleEvent $e): array => $this->present($e))->values()]);
    }

    protected function present(TempleEvent $e): array
    {
        return (new EventResource($e))->resolve(request()) + [
            'status' => ['value' => $e->status?->value, 'label' => $e->status?->getLabel()],
            'review_note' => $e->review_note,
        ];
    }
}
