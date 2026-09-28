<?php

namespace App\Http\Controllers\Api\V1\Trust;

use App\Enums\EventStatus;
use App\Enums\EventType;
use App\Http\Controllers\Api\V1\Trust\Concerns\ScopesToTrustTemples;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\EventResource;
use App\Models\TempleEvent;
use App\Support\UploadRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Festivals, programs and announcements.
 *
 * A team may ask to publish; whether it goes live at once or waits for the
 * editors is decided by TempleEventObserver from the temple's verification
 * level, exactly as in the temple portal. The response says which happened.
 *
 * Create and update both take multipart, so an image can ride along; update
 * is therefore a POST to the event's URL.
 */
class TrustEventController extends Controller
{
    use ScopesToTrustTemples;

    public function index(Request $request, int $temple): JsonResponse
    {
        $rows = $this->managedTemple($request, $temple)->events()->latest('starts_on')->limit(200)->get();

        return response()->json(['data' => $rows->map(fn (TempleEvent $e): array => $this->present($e, $request))->values()]);
    }

    public function store(Request $request, int $temple): JsonResponse
    {
        $record = $this->managedTemple($request, $temple);
        $event = new TempleEvent(['temple_id' => $record->getKey()]);

        $this->save($request, $event, $record->getKey());

        return response()->json(['data' => $this->present($event->refresh(), $request)], 201);
    }

    public function update(Request $request, int $temple, int $event): JsonResponse
    {
        $record = $this->managedTemple($request, $temple);
        $row = $record->events()->findOrFail($event);

        $this->save($request, $row, $record->getKey());

        return response()->json(['data' => $this->present($row->refresh(), $request)]);
    }

    public function destroy(Request $request, int $temple, int $event): JsonResponse
    {
        $this->managedTemple($request, $temple)->events()->findOrFail($event)->delete();

        return response()->json(['data' => ['message' => 'Event removed.']]);
    }

    protected function save(Request $request, TempleEvent $event, int $templeId): void
    {
        $validated = $request->validate([
            'type' => ['required', Rule::enum(EventType::class)],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'is_all_day' => ['required', 'boolean'],
            'starts_at' => ['nullable', 'date_format:H:i'],
            'ends_at' => ['nullable', 'date_format:H:i'],
            'recurrence' => ['nullable', Rule::in(['none', 'yearly'])],
            // A team asks for draft or published; review is not theirs to set.
            'status' => ['required', Rule::in([EventStatus::Draft->value, EventStatus::Published->value])],
            'image' => ['nullable', 'image', 'mimes:'.UploadRules::mimesRuleFor('event_image'), 'max:'.UploadRules::maxKbFor('event_image')],
            'remove_image' => ['nullable', 'boolean'],
        ]);

        if ($validated['is_all_day']) {
            $validated['starts_at'] = null;
            $validated['ends_at'] = null;
        }

        $validated['recurrence'] ??= 'none';

        $oldImage = [$event->image_disk, $event->image_path];

        if ($request->hasFile('image')) {
            $disk = config('filesystems.media');
            $event->image_disk = $disk;
            $event->image_path = $request->file('image')->store('events/'.$templeId, ['disk' => $disk]);
        } elseif ($request->boolean('remove_image')) {
            $event->image_path = null;
        }

        // An edit to a live event goes back through the same gate as a new
        // one; the observer turns "published" into "in review" if it must.
        $event->review_note = null;
        $event->fill(collect($validated)->except(['image', 'remove_image'])->all())->save();

        if (filled($oldImage[1]) && $oldImage[1] !== $event->image_path) {
            Storage::disk($oldImage[0] ?? config('filesystems.media'))->delete($oldImage[1]);
        }
    }

    /** @return array<string, mixed> */
    protected function present(TempleEvent $event, Request $request): array
    {
        return (new EventResource($event))->resolve($request) + [
            'status' => [
                'value' => $event->status?->value,
                'label' => $event->status?->getLabel(),
            ],
            'review_note' => $event->review_note,
            'recurrence' => $event->recurrence,
        ];
    }
}
