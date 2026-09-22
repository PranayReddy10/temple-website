<?php

namespace App\Observers;

use App\Enums\EventStatus;
use App\Models\TempleEvent;
use App\Support\ActingStaff;
use Illuminate\Support\Facades\Storage;

/**
 * Decides what a temple may publish without review.
 *
 * Enforced here rather than in the form because the form is only the user
 * interface: an import, a seeder or a crafted request must land on the same
 * answer. Staff are trusted; a temple team is trusted only as far as its
 * verification level, and only while the setting allows it at all.
 */
class TempleEventObserver
{
    public function creating(TempleEvent $event): void
    {
        $event->image_disk ??= config('filesystems.media');
        // A users.id, so it comes from the staff guard by name. Auth::id()
        // during an API request is a devotee's id, which belongs to a
        // different table entirely.
        $event->created_by ??= ActingStaff::id();
    }

    public function saving(TempleEvent $event): void
    {
        $this->applyModeration($event);
        $this->stampPublishedAt($event);
    }

    public function deleted(TempleEvent $event): void
    {
        if (filled($event->image_path)) {
            Storage::disk($event->image_disk ?? config('filesystems.media'))
                ->delete($event->image_path);
        }
    }

    protected function applyModeration(TempleEvent $event): void
    {
        if ($event->status !== EventStatus::Published) {
            return;
        }

        // Nobody signed in means a seeder, an import or a console command,
        // which are trusted the same way they are for temples.
        if (! ActingStaff::someoneIsSignedIn()) {
            return;
        }

        // Editorial staff publish directly. A temple admin does not, and
        // neither does anything else that happens to be authenticated.
        if (ActingStaff::user()?->role?->isStaff() ?? false) {
            return;
        }

        if (! $event->templeMaySelfPublish()) {
            // Queued rather than refused: the temple's work is kept, and staff
            // decide. Refusing would lose what they wrote.
            $event->status = EventStatus::PendingReview;
        }
    }

    /** published_at records the first time it went live and is not reset. */
    protected function stampPublishedAt(TempleEvent $event): void
    {
        if ($event->status === EventStatus::Published && $event->published_at === null) {
            $event->published_at = now();
        }
    }
}
