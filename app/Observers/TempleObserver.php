<?php

namespace App\Observers;

use App\Enums\TempleStatus;
use App\Models\Temple;
use App\Support\ActingStaff;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Keeps slug, audit stamps and publish timestamps correct no matter where a
 * temple is written from: the admin panel, a seeder, an import or tinker.
 */
class TempleObserver
{
    public function creating(Temple $temple): void
    {
        $this->guardPublishing($temple);

        $temple->slug ??= $this->uniqueSlug($temple);
        // The staff guard by name, never Auth::id(): these are foreign keys
        // into `users`, and during an API request Auth::id() is a devotee's.
        $temple->created_by ??= ActingStaff::id();
        $temple->updated_by ??= ActingStaff::id();

        $this->syncPublishedAt($temple);
    }

    public function updating(Temple $temple): void
    {
        $this->guardPublishing($temple);

        // A text someone rewrote is ours, not Wikipedia's any more.
        if ($temple->isDirty('short_description') && ! $temple->isDirty('description_source')) {
            $temple->description_source = null;
        }
        if (! $temple->isDirty('wikipedia_fields') && ! empty($temple->wikipedia_fields)) {
            $kept = array_values(array_filter($temple->wikipedia_fields, fn (string $f): bool => ! $temple->isDirty($f)));
            if ($kept !== $temple->wikipedia_fields) {
                $temple->wikipedia_fields = $kept === [] ? null : $kept;
            }
        }

        // A published temple keeps its slug: the public URL and any shared link
        // must not break because an editor corrected a spelling.
        if ($temple->isDirty('name') && $temple->status !== TempleStatus::Published) {
            $temple->slug = $this->uniqueSlug($temple);
        }

        if (($staffId = ActingStaff::id()) !== null) {
            $temple->updated_by = $staffId;
        }

        $this->syncPublishedAt($temple);
    }

    /**
     * Removing a temple for good would take its bookings, tickets, offerings
     * and settlements with it (the database cascades). Money records are
     * never erased: such a temple stays archived or in the bin.
     */
    public function forceDeleting(Temple $temple): void
    {
        foreach (['puja_bookings', 'event_registrations', 'temple_donations', 'temple_settlements'] as $table) {
            if (DB::table($table)->where('temple_id', $temple->getKey())->exists()) {
                throw ValidationException::withMessages(['temple' => 'This temple has bookings, tickets, offerings or settlements, so it cannot be deleted permanently. Archive it instead.']);
            }
        }
    }

    /**
     * Defence in depth for the publish permission.
     *
     * The form already hides Published from editors, but the form is only the
     * user interface. This blocks a crafted request, a careless import or a
     * future code path from putting an unreviewed temple in front of devotees.
     */
    protected function guardPublishing(Temple $temple): void
    {
        if ($temple->status !== TempleStatus::Published) {
            return;
        }

        // Seeders, imports and console commands run with nobody signed in
        // and are trusted; anyone actually signed in has to be a staff member
        // whose role can publish.
        //
        // The two checks are separate on purpose. Asking only the staff guard
        // would let a request authenticated as something else through the
        // same gap, because that guard returns null for it too.
        if (! ActingStaff::someoneIsSignedIn()) {
            return;
        }

        $user = ActingStaff::user();

        if ($user?->canPublish()) {
            return;
        }

        // A temple's own team keeps its live listing current — timings of
        // the office, a new phone number — without taking it offline. Only
        // the fields the portal gives them, only on a temple they are
        // approved for, and never the status itself.
        if ($user !== null
            && $user->isTempleAdmin()
            && $user->administersTemple($temple)
            && ! $temple->isDirty('status')
            && array_diff(array_keys($temple->getDirty()), Temple::TEAM_EDITABLE) === []) {
            return;
        }

        throw new AuthorizationException('Your role cannot publish temples. Set the status to In Review instead.');
    }

    /**
     * published_at records the first time a temple went live and is not reset
     * by later edits, so "recently added" stays meaningful.
     */
    protected function syncPublishedAt(Temple $temple): void
    {
        if ($temple->status === TempleStatus::Published && $temple->published_at === null) {
            $temple->published_at = now();
        }
    }

    protected function uniqueSlug(Temple $temple): string
    {
        $base = Str::slug($temple->name) ?: 'temple';
        $slug = $base;
        $suffix = 2;

        // Temple names repeat across India — there are many Shiva temples called
        // the same thing — so disambiguate rather than collide on the unique index.
        while ($this->slugTaken($slug, $temple)) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    protected function slugTaken(string $slug, Temple $temple): bool
    {
        return Temple::withTrashed()
            ->where('slug', $slug)
            ->when($temple->exists, fn ($query) => $query->whereKeyNot($temple->getKey()))
            ->exists();
    }
}
