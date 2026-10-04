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
            if (self::ownsFile($photo, $path)) {
                $disk->delete($path);
            }
        }

        $this->promoteAnotherPrimary($photo);
    }

    /**
     * Only this temple's own files are deleted with its photo: a row that
     * names another temple's file (or one still used by another photo) must
     * not take that file with it.
     */
    public static function ownsFile(TemplePhoto $photo, string $path): bool
    {
        if (str_contains($path, '..')) {
            return false;
        }
        $own = str_starts_with($path, 'temples/'.$photo->temple_id.'/') || str_starts_with($path, 'temples/covers/');

        return $own && ! TemplePhoto::query()
            ->whereKeyNot($photo->getKey())
            ->where(fn ($q) => $q->where('path', $path)->orWhere('medium_path', $path)->orWhere('thumbnail_path', $path))
            ->exists();
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
