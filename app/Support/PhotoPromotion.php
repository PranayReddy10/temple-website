<?php

namespace App\Support;

use App\Enums\PhotoCategory;
use App\Models\TemplePhoto;
use App\Models\User;
use App\Models\VisitPhoto;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * An approved Photo Stamp, offered into the temple's own gallery.
 *
 * The devotee's file is copied under the temple, not referenced: a devotee
 * later deleting their photo keeps their own copy under their control
 * without pulling a picture out of a published gallery, and the temple's
 * copy gets the medium and thumbnail renditions like any other. The credit
 * is the devotee's name; the temple can object, which unpublishes it.
 */
final class PhotoPromotion
{
    public static function promote(VisitPhoto $photo, ?User $staff): TemplePhoto
    {
        if (! $photo->canBePromoted()) {
            throw new InvalidArgumentException($photo->promotedPhoto !== null
                ? 'This photo is already in the temple\'s gallery.'
                : 'Only an approved photo the devotee chose to share can go into the gallery.');
        }

        $disk = $photo->disk ?? config('filesystems.media');
        $source = $photo->original_path;
        $extension = pathinfo($source, PATHINFO_EXTENSION) ?: 'jpg';
        $target = 'temples/'.$photo->temple_id.'/devotees/'.$photo->getKey().'.'.$extension;

        Storage::disk($disk)->copy($source, $target);

        return TemplePhoto::create([
            'temple_id' => $photo->temple_id,
            'disk' => $disk,
            'path' => $target,
            'category' => PhotoCategory::Gallery,
            'caption' => $photo->caption,
            'credit' => $photo->devotee?->name,
            'license' => 'Shared by a devotee through the app',
            'is_primary' => false,
            'is_published' => true,
            'sort_order' => 999,
            'uploaded_by' => $staff?->getKey(),
            'devotee_id' => $photo->devotee_id,
            'visit_photo_id' => $photo->getKey(),
        ]);
    }

    /** The temple does not want this photo of itself shown. */
    public static function object(TemplePhoto $photo, string $reason): TemplePhoto
    {
        $photo->forceFill([
            'is_published' => false,
            'is_primary' => false,
            'temple_objected_at' => now(),
            'temple_objection' => $reason,
        ])->save();

        return $photo;
    }
}
