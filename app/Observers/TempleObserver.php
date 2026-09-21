<?php

namespace App\Observers;

use App\Enums\TempleStatus;
use App\Models\Temple;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

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
        $temple->created_by ??= Auth::id();
        $temple->updated_by ??= Auth::id();

        $this->syncPublishedAt($temple);
    }

    public function updating(Temple $temple): void
    {
        $this->guardPublishing($temple);

        // A published temple keeps its slug: the public URL and any shared link
        // must not break because an editor corrected a spelling.
        if ($temple->isDirty('name') && $temple->status !== TempleStatus::Published) {
            $temple->slug = $this->uniqueSlug($temple);
        }

        if (Auth::check()) {
            $temple->updated_by = Auth::id();
        }

        $this->syncPublishedAt($temple);
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

        // Seeders, imports and console commands run without an authenticated
        // user and are trusted; only an actual signed-in editor is restricted.
        $user = Auth::user();

        if ($user !== null && ! $user->canPublish()) {
            throw new AuthorizationException('Your role cannot publish temples. Set the status to In Review instead.');
        }
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
