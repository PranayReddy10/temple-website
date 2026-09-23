<?php

namespace App\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Whether uploaded files can actually be seen, and why not when they cannot.
 *
 * "The images do not show" has several possible causes that look identical
 * from the admin panel: the public/storage link was never created, it was
 * created and then lost by a redeploy, the host forbids symlinks outright,
 * the media disk is set to Spaces with no credentials, or the files are
 * simply not there. Guessing between them by eye is how an afternoon goes.
 *
 * So each one is checked separately and reported separately.
 */
final class StorageHealth
{
    /** Where the link has to be for the web server to follow it. */
    public static function linkPath(): string
    {
        return public_path('storage');
    }

    public static function targetPath(): string
    {
        return storage_path('app/public');
    }

    public static function mediaDisk(): string
    {
        return (string) config('filesystems.media', 'public');
    }

    public static function mediaDiskDriver(): string
    {
        return (string) config('filesystems.disks.'.self::mediaDisk().'.driver', 'local');
    }

    public static function isLocal(): bool
    {
        return self::mediaDiskDriver() === 'local';
    }

    // --- The link ---

    public static function linkExists(): bool
    {
        return file_exists(self::linkPath());
    }

    public static function isSymlink(): bool
    {
        return is_link(self::linkPath());
    }

    /**
     * Whether the link points where it should.
     *
     * A link created on a different path — a staging copy, or a host that
     * moved the account between servers — still exists and still fails, and
     * the failure looks exactly like a missing link.
     */
    public static function linkIsCorrect(): bool
    {
        if (! self::linkExists()) {
            return false;
        }

        $resolved = realpath(self::linkPath());
        $target = realpath(self::targetPath());

        return $resolved !== false && $target !== false && $resolved === $target;
    }

    /**
     * Some shared hosts disable symlink() outright, and some disable it only
     * for the web user. Worth knowing before telling somebody to run a
     * command that cannot work.
     */
    public static function symlinksAreAllowed(): bool
    {
        if (! function_exists('symlink')) {
            return false;
        }

        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return ! in_array('symlink', $disabled, true);
    }

    // --- The files themselves ---

    public static function targetIsWritable(): bool
    {
        return is_dir(self::targetPath()) && is_writable(self::targetPath());
    }

    /**
     * A file that really exists on the media disk, for probing whether files
     * can be read back over HTTP.
     *
     * Any file will do; the newest is likeliest to be one the person is
     * looking at right now.
     */
    public static function sampleFile(): ?string
    {
        try {
            $disk = Storage::disk(self::mediaDisk());

            foreach (['temples', 'deities', 'visit-photos', 'pujas', 'events', 'devotional', 'demo'] as $directory) {
                $files = $disk->files($directory, recursive: true);

                foreach ($files as $file) {
                    if (in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'webp'], true)) {
                        return $file;
                    }
                }
            }

            return null;
        } catch (Throwable) {
            // An unreachable disk is a finding of its own, reported elsewhere.
            return null;
        }
    }

    public static function sampleUrl(): ?string
    {
        $file = self::sampleFile();

        return $file === null ? null : Storage::disk(self::mediaDisk())->url($file);
    }

    // --- Spaces ---

    /** @return array<string, bool> which Spaces settings are filled in */
    public static function spacesConfiguration(): array
    {
        $disk = config('filesystems.disks.spaces', []);

        return [
            'key' => filled($disk['key'] ?? null),
            'secret' => filled($disk['secret'] ?? null),
            'bucket' => filled($disk['bucket'] ?? null),
            'endpoint' => filled($disk['endpoint'] ?? null),
            'url' => filled($disk['url'] ?? null),
        ];
    }

    public static function spacesIsConfigured(): bool
    {
        return ! in_array(false, self::spacesConfiguration(), true);
    }

    /**
     * Actually talk to the disk rather than inspecting its settings.
     *
     * Credentials that are present and wrong look identical to credentials
     * that are present and right, until something tries to use them.
     *
     * @return array{ok: bool, message: string}
     */
    public static function probeDisk(): array
    {
        try {
            $disk = Storage::disk(self::mediaDisk());
            $path = 'health/'.now()->format('Ymd-His').'-'.Str::random(6).'.txt';

            $disk->put($path, 'storage health check');
            $readBack = $disk->get($path);
            $disk->delete($path);

            if ($readBack !== 'storage health check') {
                return ['ok' => false, 'message' => 'The file was written but read back wrong.'];
            }

            return ['ok' => true, 'message' => 'Wrote a file, read it back and deleted it.'];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => class_basename($e).': '.$e->getMessage()];
        }
    }

    // --- The overall verdict ---

    /**
     * Whether uploaded files can be seen at all.
     *
     * On Spaces the link is irrelevant; on a local disk it is the whole
     * question.
     */
    public static function mediaIsServable(): bool
    {
        return self::isLocal() ? self::linkIsCorrect() : self::spacesIsConfigured();
    }

    /**
     * Used bytes on the media disk, which is what fills a shared plan.
     *
     * Null rather than zero when it cannot be worked out — a cloud disk has
     * no cheap answer, and reporting zero would read as "plenty of room".
     */
    /**
     * The largest upload PHP itself will accept, in kilobytes.
     *
     * The limit that nobody thinks to look at. A form can be set to 50 MB and
     * validate a 50 MB file quite happily, and the upload will still fail —
     * before any of our code runs, with an empty $_FILES and no error worth
     * reading — because upload_max_filesize is 2 MB, which is what shared
     * plans ship. post_max_size caps it too, and the smaller of the two wins.
     *
     * Returns null when neither is set, which means unlimited.
     */
    public static function phpUploadLimitKb(): ?int
    {
        $limits = collect(['upload_max_filesize', 'post_max_size'])
            ->map(fn (string $setting): ?int => self::iniToKilobytes(ini_get($setting)))
            ->filter()
            ->values();

        return $limits->isEmpty() ? null : (int) $limits->min();
    }

    /** "8M", "512K", "1G" or a plain byte count, as kilobytes. */
    public static function iniToKilobytes(string|false $value): ?int
    {
        $value = trim((string) $value);

        if ($value === '' || $value === '-1' || $value === '0') {
            return null;
        }

        $number = (float) $value;
        $unit = strtolower(substr($value, -1));

        return (int) match ($unit) {
            'g' => $number * 1024 * 1024,
            'm' => $number * 1024,
            'k' => $number,
            // No suffix means bytes.
            default => $number / 1024,
        };
    }

    public static function usedBytes(): ?int
    {
        if (! self::isLocal()) {
            return null;
        }

        $root = config('filesystems.disks.'.self::mediaDisk().'.root');

        if (! is_string($root) || ! is_dir($root)) {
            return null;
        }

        $total = 0;

        foreach (File::allFiles($root) as $file) {
            $total += $file->getSize();
        }

        return $total;
    }

    public static function formatBytes(?int $bytes): string
    {
        if ($bytes === null) {
            return 'Not measurable here';
        }

        foreach ([['GB', 1073741824], ['MB', 1048576], ['KB', 1024]] as [$unit, $size]) {
            if ($bytes >= $size) {
                return round($bytes / $size, 1).' '.$unit;
            }
        }

        return $bytes.' bytes';
    }
}
