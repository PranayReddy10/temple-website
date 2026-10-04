<?php

namespace App\Http\Controllers\Api\V1\Trust;

use App\Enums\BookingStatus;
use App\Enums\EventStatus;
use App\Enums\EventType;
use App\Http\Controllers\Api\V1\Trust\Concerns\ScopesToTrustTemples;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\EventResource;
use App\Models\Temple;
use App\Models\TempleEvent;
use App\Models\TemplePayoutAccount;
use App\Support\UploadRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
        $rows = $this->managedTemple($request, $temple)->events()->with('devotee:id,name')
            // Waiting for approval first, then the newest.
            ->orderByRaw("case when status = 'pending_review' then 0 else 1 end")
            ->latest('starts_on')->limit(200)->get();

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

    /**
     * The temple's owner approves an event waiting for review: one a
     * devotee proposed, or one a manager wrote. It goes live in the app.
     */
    public function approve(Request $request, int $temple, int $event): JsonResponse
    {
        $row = $this->reviewable($request, $temple, $event);
        $row->update(['status' => EventStatus::Published, 'reviewed_by' => $this->trustUser($request)->getKey(), 'review_note' => null]);

        return response()->json(['data' => $this->present($row->refresh()->load('devotee:id,name'), $request)]);
    }

    /** The owner turns it down, saying why: whoever proposed it sees the reason. */
    public function reject(Request $request, int $temple, int $event): JsonResponse
    {
        $validated = $request->validate(['note' => ['required', 'string', 'max:1000']]);
        $row = $this->reviewable($request, $temple, $event);
        $row->update(['status' => EventStatus::Rejected, 'reviewed_by' => $this->trustUser($request)->getKey(), 'review_note' => $validated['note']]);

        return response()->json(['data' => $this->present($row->refresh()->load('devotee:id,name'), $request)]);
    }

    protected function reviewable(Request $request, int $temple, int $event): TempleEvent
    {
        /** @var TempleEvent $row */
        $row = $this->managedTemple($request, $temple)->events()->findOrFail($event);
        abort_unless($row->mayBeReviewedBy($this->trustUser($request)), 403, 'Only the temple\'s owner can approve events.');
        if ($row->status !== EventStatus::PendingReview) {
            throw ValidationException::withMessages(['status' => 'This event is not waiting for approval.']);
        }

        return $row;
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
            'recurrence' => ['nullable', Rule::in(TempleEvent::RECURRENCES)],
            // Bhajan gatherings and ticketed events.
            'group_name' => ['nullable', 'string', 'max:160'],
            'open_to_all' => ['nullable', 'boolean'],
            'registration_enabled' => ['nullable', 'boolean'],
            // Rupees per person; empty or 0 for free.
            'ticket_price' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'max_people_per_registration' => ['nullable', 'integer', 'min:1', 'max:500'],
            'songs' => ['nullable', 'string', 'max:5000'],
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
        $validated['ticket_price_paise'] = (int) round(((float) ($validated['ticket_price'] ?? 0)) * 100);
        foreach (['open_to_all' => true, 'registration_enabled' => false, 'max_people_per_registration' => 10] as $key => $default) {
            $validated[$key] ??= $default;
        }

        // Paid tickets take money: only once the owner and the bank details
        // are approved.
        if ($validated['registration_enabled'] && $validated['ticket_price_paise'] > 0
            && ! (Temple::query()->find($templeId)?->canCollectPayments() ?? false)) {
            throw ValidationException::withMessages(['ticket_price' => TemplePayoutAccount::NOT_APPROVED_MESSAGE]);
        }

        // A price is set before tickets are sold, not changed under them.
        if ($event->exists && $event->ticket_price_paise !== $validated['ticket_price_paise']
            && $event->registrations()->where('amount_paise', '>', 0)->whereIn('status', ['pending_payment', 'confirmed', 'verified'])->exists()) {
            throw ValidationException::withMessages(['ticket_price' => 'Tickets are already sold at the current price. Create a new event for a new price.']);
        }

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
        $event->fill(collect($validated)->except(['image', 'remove_image', 'ticket_price'])->all())->save();

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
            // May this person approve or reject it (the temple's owner)?
            'can_review' => $event->status === EventStatus::PendingReview && $event->mayBeReviewedBy($this->trustUser($request)),
            'recurrence' => $event->recurrence,
            // The editor's own values, and who is coming.
            'ticket_price' => $event->ticket_price_paise / 100,
            'songs_text' => $event->songs,
            'registrations_summary' => $this->summary($event),
        ];
    }

    /**
     * Who is coming, by date: "I'll join" and tickets, with the money.
     */
    public function registrations(Request $request, int $temple, int $event): JsonResponse
    {
        $row = $this->managedTemple($request, $temple)->events()->findOrFail($event);
        $validated = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $date = $validated['date'] ?? $row->nextDate()?->toDateString() ?? $row->starts_on->toDateString();

        $items = $row->registrations()
            ->whereDate('occurs_on', $date)
            ->with('payment')
            ->orderBy('devotee_name')
            ->get();

        $live = $items->filter(fn ($r) => $r->isLive() || $r->status === BookingStatus::Expired);

        return response()->json(['data' => [
            'date' => $date,
            'dates' => collect($row->nextDates(null, 12))->map(fn ($d) => $d->toDateString())->values(),
            'summary' => [
                'registrations' => $items->filter(fn ($r) => $r->isLive())->count(),
                'people' => (int) $items->filter(fn ($r) => $r->isLive())->sum('people'),
                'received' => $items->filter(fn ($r) => $r->isVerified())->count(),
                'amount_paise' => (int) $live->sum('amount_paise'),
                'amount' => '₹'.number_format($live->sum('amount_paise') / 100, 2),
                'capacity' => $row->capacity,
            ],
            'items' => $items->map(fn ($r) => [
                'reference' => $r->reference,
                'devotee_name' => $r->devotee_name,
                'devotee_phone' => $r->devotee_phone,
                'people' => $r->people,
                'amount_paise' => $r->amount_paise,
                'amount' => $r->amountLabel(),
                'status' => ['value' => $r->status->value, 'label' => $r->status->getLabel()],
            ])->values(),
        ]]);
    }

    /** @return array<string, mixed>|null */
    protected function summary(TempleEvent $event): ?array
    {
        if (! $event->registration_enabled) {
            return null;
        }

        $next = $event->nextDate();

        return [
            'next_on' => $next?->toDateString(),
            'going' => $next === null ? 0 : $event->goingOn($next),
            'total_people' => (int) $event->registrations()->live()->sum('people'),
            'total_amount_paise' => (int) $event->registrations()->whereIn('status', ['confirmed', 'verified', 'expired'])->sum('amount_paise'),
        ];
    }
}
