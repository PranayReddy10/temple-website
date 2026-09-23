<?php

namespace App\Filament\Concerns;

use App\Enums\PhotoCategory;
use App\Models\Temple;
use App\Models\TemplePhoto;

/**
 * Binds the temple form's cover image field to the primary temple_photos row.
 *
 * The cover is not a column on temples, and deliberately so: temples already
 * have a photo table with a primary flag, and adding a second home for the
 * same image would mean two records that can disagree about which photo leads
 * — with nothing to say which one the app should believe.
 *
 * What was missing was a way to set it while creating a temple. The gallery
 * is a relation manager, and a relation manager only exists once the record
 * is saved, so a temple could be created, published and listed with no image
 * at all and the person creating it had no way to notice.
 */
trait SyncsCoverPhoto
{
    /** @var array<string, mixed> */
    protected array $coverPhotoState = [];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function extractCoverPhoto(array $data): array
    {
        // Held aside rather than saved: these are not columns on temples, and
        // passing them through would fail on the insert.
        $this->coverPhotoState = [
            'path' => self::firstPath($data['cover_image'] ?? null),
            'credit' => $data['cover_image_credit'] ?? null,
            // Distinguishes "cleared it" from "the form never showed it",
            // which must not be treated the same way.
            'submitted' => array_key_exists('cover_image', $data),
        ];

        unset($data['cover_image'], $data['cover_image_credit']);

        return $data;
    }

    protected function syncCoverPhoto(Temple $temple): void
    {
        if (! ($this->coverPhotoState['submitted'] ?? false)) {
            return;
        }

        $path = $this->coverPhotoState['path'] ?? null;
        $credit = $this->coverPhotoState['credit'] ?? null;

        $current = $temple->photos()->where('is_primary', true)->first();

        if (blank($path)) {
            /*
             * Cleared, so the photo stops leading — it is not deleted.
             *
             * Someone emptying this field is saying "not this one as the
             * cover", not "destroy this photograph". Deleting it would also
             * take the file with it through the observer, and a temple
             * photograph is not always recoverable.
             */
            $current?->forceFill(['is_primary' => false])->saveQuietly();

            return;
        }

        if ($current !== null && $current->path === $path) {
            // Same image, possibly a corrected credit.
            $current->update(['credit' => $credit]);

            return;
        }

        $temple->photos()->create([
            'disk' => config('filesystems.media'),
            'path' => $path,
            'category' => PhotoCategory::Exterior,
            'credit' => $credit,
            'is_primary' => true,
            'is_published' => true,
        ]);

        // The observer demotes whatever was primary before, so the old cover
        // stays in the gallery rather than disappearing.
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function fillCoverPhoto(array $data, ?Temple $temple): array
    {
        $primary = $temple?->photos()->where('is_primary', true)->first();

        // A list, because that is the shape a FileUpload holds — it supports
        // multiple files, so its state is always a collection of paths even
        // when only one is allowed.
        $data['cover_image'] = $primary === null ? [] : [$primary->path];
        $data['cover_image_credit'] = $primary?->credit;

        return $data;
    }

    /**
     * The single path out of a file upload's state.
     *
     * Accepts a bare string as well as the list Filament actually stores.
     * The two shapes are easy to confuse — a string round-trips through the
     * form without complaint until validation runs — and silently taking the
     * wrong one would mean a cover that appears to save and does not.
     */
    protected static function firstPath(mixed $state): ?string
    {
        if (is_string($state)) {
            return $state ?: null;
        }

        if (! is_array($state)) {
            return null;
        }

        $first = collect($state)->filter(fn ($value): bool => is_string($value) && $value !== '')->first();

        return $first ?: null;
    }

    /** @return array<int, string> */
    protected function coverPhotoFieldNames(): array
    {
        return ['cover_image', 'cover_image_credit'];
    }

    protected function currentCoverPhoto(Temple $temple): ?TemplePhoto
    {
        return $temple->photos()->where('is_primary', true)->first();
    }
}
