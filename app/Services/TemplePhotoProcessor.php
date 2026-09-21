<?php

namespace App\Services;

use App\Models\TemplePhoto;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Generates display variants for an uploaded temple photo and records its
 * dimensions.
 *
 * Runs synchronously. That is a deliberate choice for shared hosting, which has
 * no long-running queue worker: this only ever runs on an admin upload, where a
 * second or two is acceptable, never on a devotee-facing request. When photo
 * volume grows, move the call into a queued job — the interface does not change.
 */
class TemplePhotoProcessor
{
    /** Longest edge for each variant, in pixels. */
    public const MEDIUM_WIDTH = 1200;

    public const THUMBNAIL_WIDTH = 400;

    public function process(TemplePhoto $photo): void
    {
        $disk = Storage::disk($photo->disk);

        if (blank($photo->path) || ! $disk->exists($photo->path)) {
            return;
        }

        try {
            $original = $disk->get($photo->path);
            $manager = new ImageManager(new Driver());
            $image = $manager->read($original);

            $photo->width = $image->width();
            $photo->height = $image->height();
            $photo->size_bytes = strlen($original);
            $photo->mime_type = $disk->mimeType($photo->path) ?: $photo->mime_type;

            $photo->medium_path = $this->writeVariant($photo, $manager, $original, 'medium', self::MEDIUM_WIDTH);
            $photo->thumbnail_path = $this->writeVariant($photo, $manager, $original, 'thumb', self::THUMBNAIL_WIDTH);

            $photo->saveQuietly();
        } catch (Throwable $e) {
            // A variant failure must not lose the upload: the original is
            // already stored and the model falls back to it for every size.
            Log::warning('Temple photo variant generation failed.', [
                'photo_id' => $photo->id,
                'path' => $photo->path,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function writeVariant(
        TemplePhoto $photo,
        ImageManager $manager,
        string $original,
        string $suffix,
        int $maxWidth,
    ): ?string {
        $image = $manager->read($original);

        // scaleDown never enlarges, so a small original is left alone rather
        // than being upscaled into a blurry "large" version.
        $image->scaleDown(width: $maxWidth);

        [$encoded, $extension] = $this->encode($image);

        $path = $this->variantPath($photo->path, $suffix, $extension);
        Storage::disk($photo->disk)->put($path, (string) $encoded, 'public');

        return $path;
    }

    /**
     * WebP where the GD build supports it, JPEG otherwise. Shared hosting GD
     * builds vary, and a missing WebP encoder should degrade rather than throw.
     */
    protected function encode($image): array
    {
        $gd = function_exists('gd_info') ? gd_info() : [];

        if (! empty($gd['WebP Support'])) {
            return [$image->toWebp(quality: 82), 'webp'];
        }

        return [$image->toJpeg(quality: 85), 'jpg'];
    }

    protected function variantPath(string $originalPath, string $suffix, string $extension): string
    {
        $directory = trim(dirname($originalPath), '.');
        $name = pathinfo($originalPath, PATHINFO_FILENAME);
        $file = "{$name}-{$suffix}.{$extension}";

        return $directory === '' ? $file : "{$directory}/{$file}";
    }
}
