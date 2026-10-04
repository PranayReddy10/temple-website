<?php

namespace App\Http\Resources\V1;

use App\Models\TempleEvent;
use App\Support\DevotionalClock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TempleEvent */
class EventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type?->value,
            'title' => $this->title,
            'description' => $this->description,
            'image_url' => $this->imageUrl(),

            'starts_on' => $this->starts_on?->toDateString(),
            // A weekly gathering's last week, or null while it goes on; an
            // editor that sends this back must not end the series.
            'ends_on' => $this->isWeekly() ? $this->ends_on?->toDateString() : $this->lastDay()->toDateString(),
            'date_label' => $this->dateLabel(),
            'is_all_day' => $this->is_all_day,
            'starts_at' => $this->starts_at ? substr((string) $this->starts_at, 0, 5) : null,
            'ends_at' => $this->ends_at ? substr((string) $this->ends_at, 0, 5) : null,
            'is_happening_today' => $this->occursOn(DevotionalClock::now()),
            // One-off, yearly, or weekly on the first date's weekday.
            'recurrence' => $this->recurrence,
            'next_on' => $this->nextDate()?->toDateString(),
            'next_dates' => collect($this->nextDates(null, 6))->map(fn ($d) => $d->toDateString())->values(),

            // Raised by a devotee from the app rather than by the temple's
            // team; always free.
            'raised_by_devotee' => $this->isRaisedByDevotee(),
            'raised_by' => $this->whenLoaded('devotee', fn () => $this->devotee?->name),

            // Who leads it, whether anyone may come, what will be sung.
            'group_name' => $this->group_name,
            'open_to_all' => (bool) $this->open_to_all,
            'songs' => $this->songList(),

            // "I'll join" for a free gathering, tickets for a paid one.
            'registration' => [
                'enabled' => (bool) $this->registration_enabled && (! $this->isTicketed() || (bool) $this->temple?->canCollectPayments()),
                'is_paid' => $this->isTicketed(),
                'price_paise' => (int) $this->ticket_price_paise,
                'price' => $this->priceLabel(),
                'capacity' => $this->capacity,
                'max_people' => (int) $this->max_people_per_registration,
                // People coming on the next date.
                'going' => $this->registration_enabled && $this->nextDate() !== null ? $this->goingOn($this->nextDate()) : 0,
            ],

            'temple' => $this->whenLoaded('temple', fn () => [
                'id' => $this->temple->getKey(),
                'slug' => $this->temple->slug,
                'name' => $this->temple->localName(),
                'city' => $this->temple->city,
            ]),
        ];
    }
}
