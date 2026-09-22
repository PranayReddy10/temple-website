<?php

namespace App\Observers;

use App\Models\DevotionalMedia;
use Illuminate\Support\Facades\Storage;

/**
 * Enforces the rights rule on every write.
 *
 * The admin form guides an editor towards a licence, but the form is only the
 * user interface. A seeder, an import or a crafted request must not be able to
 * publish a recording we have no stated right to distribute.
 */
class DevotionalMediaObserver
{
    public function creating(DevotionalMedia $media): void
    {
        if ($media->source_type === 'upload') {
            $media->disk ??= config('filesystems.media');
        }
    }

    public function saving(DevotionalMedia $media): void
    {
        // Unlicensed recordings are forced back to unpublished rather than
        // rejected, so an editor can save a draft while they chase the rights.
        if ($media->is_published && ! $media->mayBePublished()) {
            $media->is_published = false;
        }

        // An external link has no stored file, and an upload has no link.
        // Keeping both would make url() ambiguous.
        if ($media->source_type === 'external') {
            $media->path = null;
        } else {
            $media->external_url = null;
        }
    }

    public function deleted(DevotionalMedia $media): void
    {
        if ($media->source_type !== 'upload') {
            return;
        }

        $disk = Storage::disk($media->disk ?? config('filesystems.media'));

        foreach (array_filter([$media->path, $media->thumbnail_path]) as $path) {
            $disk->delete($path);
        }
    }
}
