<?php

namespace App\Observers;

use App\Enums\EventStatus;
use App\Models\Temple;
use App\Models\TempleEvent;
use App\Models\TemplePayoutAccount;
use App\Support\ActingStaff;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

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
        $this->guardMoney($event);
        $this->applyModeration($event);
        $this->stampPublishedAt($event);
    }

    /**
     * Paid tickets take money only once the temple's owner and bank are
     * approved (for a temple team), and a price is set before tickets are
     * sold, never changed under them (for anyone).
     */
    protected function guardMoney(TempleEvent $event): void
    {
        if (ActingStaff::user()?->isTempleAdmin()
            && $event->isDirty(['registration_enabled', 'ticket_price_paise'])
            && $event->registration_enabled && (int) $event->ticket_price_paise > 0
            && ! (Temple::query()->find($event->temple_id)?->canCollectPayments() ?? false)) {
            throw ValidationException::withMessages(['ticket_price_paise' => TemplePayoutAccount::NOT_APPROVED_MESSAGE]);
        }

        if ($event->exists && $event->isDirty('ticket_price_paise')
            && $event->registrations()->where('amount_paise', '>', 0)->whereIn('status', ['pending_payment', 'confirmed', 'verified'])->exists()) {
            throw ValidationException::withMessages(['ticket_price_paise' => 'Tickets are already sold at the current price. Create a new event for a new price.']);
        }
    }

    public function deleted(TempleEvent $event): void
    {
        // Only the event's own temple's image folder: a path naming another
        // temple's file is left alone.
        if (filled($event->image_path)
            && str_starts_with($event->image_path, 'events/'.$event->temple_id.'/')
            && ! str_contains($event->image_path, '..')) {
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

        // Editorial staff publish directly, and so does the temple's own
        // owner: it is their temple. A manager does not, and neither does
        // anything else that happens to be authenticated; their event waits
        // for the owner or our staff.
        if ($event->mayBeReviewedBy(ActingStaff::user())) {
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
