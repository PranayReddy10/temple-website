<?php

namespace App\Support\TempleImport;

use App\Enums\PhotoCategory;
use App\Models\Temple;
use App\Models\TemplePhoto;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Copies a picked photo into a temple's gallery, with who took it, the
 * licence or permission it is used under, and where it came from, so the
 * credit shows wherever the photo does.
 */
final class PhotoImporter
{
    public const MAX_BYTES = 15 * 1024 * 1024;

    /**
     * @param  array{url: string, credit?: ?string, license?: ?string, source_url?: ?string, title?: ?string}  $photo
     *
     * @throws \RuntimeException with a message for staff when it cannot be copied
     */
    public static function import(Temple $temple, array $photo, bool $publish = true): TemplePhoto
    {
        // The same file is not copied twice.
        $source = (string) ($photo['source_url'] ?? $photo['url']);
        if ($existing = $temple->photos()->where('source_url', $source)->first()) {
            return $existing;
        }

        try {
            $response = Http::timeout(30)->withHeaders(['User-Agent' => config('brand.name').' temple directory ('.config('brand.website').')'])->get($photo['url']);
        } catch (Throwable) {
            throw new \RuntimeException('Could not download '.$photo['url'].'.');
        }
        $type = strtolower(strtok((string) $response->header('Content-Type'), ';'));
        $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$type] ?? null;
        $body = (string) $response->body();
        if (! $response->successful() || $extension === null || strlen($body) === 0 || strlen($body) > self::MAX_BYTES || @getimagesizefromstring($body) === false) {
            throw new \RuntimeException('Not a photo that can be copied: '.$photo['url'].'.');
        }

        $disk = config('filesystems.media');
        $path = 'temples/'.$temple->getKey().'/imported/'.Str::random(20).'.'.$extension;
        Storage::disk($disk)->put($path, $body);

        return $temple->photos()->create([
            'disk' => $disk,
            'path' => $path,
            'category' => PhotoCategory::Gallery,
            'caption' => filled($photo['title'] ?? null) ? Str::limit($photo['title'], 250, '') : null,
            'credit' => Str::limit((string) ($photo['credit'] ?? ''), 250, '') ?: null,
            'license' => Str::limit((string) ($photo['license'] ?? ''), 250, '') ?: null,
            'source_url' => $source,
            'is_primary' => false,
            'is_published' => $publish,
            'sort_order' => 500,
        ]);
    }
}
