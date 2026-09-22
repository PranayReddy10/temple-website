<?php

namespace App\Observers;

use App\Models\TemplePhoto;
use App\Services\TemplePhotoProcessor;
use App\Support\ActingStaff;
use Illuminate\Support\Facades\Storage;

class TemplePhotoObserver
{
    public function __construct(protected TemplePhotoProcessor $processor) {}

    public function creating(TemplePhoto $photo): void
    {
        $photo->disk ??= config('filesystems.media');
        $photo->uploaded_by ??= ActingStaff::id();
    }

    public function created(TemplePhoto $photo): void
    {
        $this->processor->process($photo);
        $this->enforceSinglePrimary($photo);
        $this->ensureTempleHasAPrimary($photo);
    }

    public function updated(TemplePhoto $photo): void
    {
        if ($photo->wasChanged('is_primary')) {
            $this->enforceSinglePrimary($photo);
        }
    }

    /**
     * Deleting the row must delete the files too, otherwise object storage
     * accumulates orphans nobody can find or bill back to a temple.
     */
    public function deleted(TemplePhoto $photo): void
    {
        $disk = Storage::disk($photo->disk ?? config('filesystems.media'));

        foreach ($photo->storedPaths() as $path) {
            $disk->delete($path);
        }

        $this->promoteAnotherPrimary($photo);
    }

    /** A temple has exactly one lead image, or none at all. */
    protected function enforceSinglePrimary(TemplePhoto $photo): void
    {
        if (! $photo->is_primary) {
            return;
        }

        TemplePhoto::where('temple_id', $photo->temple_id)
            ->whereKeyNot($photo->getKey())
            ->where('is_primary', true)
            ->update(['is_primary' => false]);
    }

    /** The first photo uploaded for a temple becomes its lead image. */
    protected function ensureTempleHasAPrimary(TemplePhoto $photo): void
    {
        $hasPrimary = TemplePhoto::where('temple_id', $photo->temple_id)
            ->where('is_primary', true)
            ->exists();

        if (! $hasPrimary) {
            $photo->forceFill(['is_primary' => true])->saveQuietly();
        }
    }

    /** Losing the lead image should not leave the temple without one. */
    protected function promoteAnotherPrimary(TemplePhoto $photo): void
    {
        if (! $photo->is_primary) {
            return;
        }

        $replacement = TemplePhoto::where('temple_id', $photo->temple_id)
            ->published()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();

        $replacement?->forceFill(['is_primary' => true])->saveQuietly();
    }
}
