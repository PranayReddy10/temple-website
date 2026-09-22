<?php

namespace App\Observers;

use App\Enums\EventStatus;
use App\Models\TempleEvent;
use Illuminate\Support\Facades\Auth;
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
        $event->created_by ??= Auth::id();
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

        $user = Auth::user();

        // No signed-in user means a seeder, an import or a console command,
        // which are trusted the same way they are for temples.
        if ($user === null || $user->role?->isStaff()) {
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
