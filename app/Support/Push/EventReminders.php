<?php

namespace App\Support\Push;

use App\Enums\EventType;
use App\Enums\TempleStatus;
use App\Models\AppNotification;
use App\Models\TempleEvent;
use App\Models\TempleFollow;
use App\Support\DevotionalClock;

/**
 * Tomorrow's festivals and programs, told to the people who asked.
 *
 * The first notification here that can annoy people, so it is built to be
 * quiet: only followers who switched reminders on for that temple and that
 * kind of event get one, one per event, the day before, never twice
 * (`source_key`), and none at all while the switch in Settings is off.
 */
final class EventReminders
{
    public const SETTING = 'notifications_event_reminders';

    public function __construct(protected NotificationSender $sender) {}

    /** @return int how many reminders went out */
    public function sendDue(): int
    {
        if (! (bool) setting(self::SETTING, null, true)) {
            return 0;
        }

        $tomorrow = DevotionalClock::now()->addDay()->toDateString();
        $sent = 0;

        TempleEvent::query()
            ->published()
            ->whereDate('starts_on', $tomorrow)
            ->whereHas('temple', fn ($q) => $q->where('status', TempleStatus::Published))
            ->with('temple:id,slug,name,city')
            ->each(function (TempleEvent $event) use (&$sent): void {
                $audience = $event->type === EventType::Festival ? 'temple_festival' : 'temple_event';
                $column = AppNotification::REMINDER_AUDIENCES[$audience];

                // Nobody asked: nothing to say, and no row to clutter the list.
                if (! TempleFollow::query()->where('temple_id', $event->temple_id)->where($column, true)->exists()) {
                    return;
                }

                $key = 'event:'.$event->getKey().':reminder';

                if (AppNotification::query()->where('source_key', $key)->exists()) {
                    return;
                }

                $notification = AppNotification::create([
                    'title' => str($event->title)->limit(60)->toString(),
                    'body' => $this->body($event),
                    'image_url' => $event->imageUrl(),
                    'link_type' => 'temple',
                    'link_value' => $event->temple->slug,
                    'audience' => $audience,
                    'audience_id' => $event->temple_id,
                    'status' => 'scheduled',
                    'scheduled_at' => now(),
                    'source_key' => $key,
                ]);

                $this->sender->send($notification);
                $sent++;
            });

        return $sent;
    }

    protected function body(TempleEvent $event): string
    {
        $when = $event->is_all_day || blank($event->starts_at)
            ? 'Tomorrow'
            : 'Tomorrow at '.substr((string) $event->starts_at, 0, 5);
        $kind = match ($event->type) {
            EventType::Festival => 'Festival',
            default => $event->type?->getLabel() ?? 'Event',
        };

        return str("{$when} at {$event->temple->name}: {$kind}.".(filled($event->description) ? ' '.$event->description : ''))->limit(230)->toString();
    }
}
