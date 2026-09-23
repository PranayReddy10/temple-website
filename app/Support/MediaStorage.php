<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Throwable;

/**
 * Where uploads go, decided in the admin panel rather than in .env.
 *
 * Moving photos to Spaces used to mean editing .env over SSH, guessing at five
 * variable names, and finding out whether they were right by uploading
 * something and seeing whether it appeared. Get one wrong and every new upload
 * fails silently. Whoever runs this site should be able to make that change
 * from the panel, and should not be able to make it badly.
 *
 * Two rules hold the whole thing up:
 *
 *  1. **Nothing switches until it has been proven.** Turning Spaces on runs a
 *     real write, read and delete against the credentials being saved, and
 *     refuses the switch if any of it fails. Bad credentials cannot be saved
 *     into service.
 *
 *  2. **A switch never moves a file.** Every row that holds a file also holds
 *     the disk it was written to, so photos uploaded before the change keep
 *     resolving from where they are. Switching decides where the *next* upload
 *     goes, which makes it reversible: flip back and nothing is lost.
 *
 * .env still works and still means something. A setting overrides it; clearing
 * the setting falls back to it. A host that would rather keep its secrets out
 * of the database can set them in .env and never open this screen.
 */
final class MediaStorage
{
    /** The local disk media is written to when Spaces is off. */
    public const LOCAL_DISK = 'public';

    public const SPACES_DISK = 'spaces';

    /** Setting key => the config path it overrides. */
    public const SPACES_KEYS = [
        'spaces_key' => 'filesystems.disks.spaces.key',
        'spaces_secret' => 'filesystems.disks.spaces.secret',
        'spaces_region' => 'filesystems.disks.spaces.region',
        'spaces_bucket' => 'filesystems.disks.spaces.bucket',
        'spaces_endpoint' => 'filesystems.disks.spaces.endpoint',
        'spaces_cdn_endpoint' => 'filesystems.disks.spaces.url',
    ];

    /** The one that is never read back out, only replaced. */
    public const SECRET_KEY = 'spaces_secret';

    /**
     * Folds the stored settings over the config, at boot.
     *
     * Deliberately not read at each call site. Config is what every disk in
     * the framework is built from, and half the application asking settings
     * while the other half asks config is how the two drift apart. This runs
     * once, before anything resolves a disk, and afterwards there is one
     * answer to where uploads go.
     */
    public static function apply(): void
    {
        foreach (self::SPACES_KEYS as $setting => $path) {
            $value = self::storedSpacesValue($setting);

            if (filled($value)) {
                Config::set($path, $value);
            }
        }

        // The CDN endpoint is what images are delivered from. Without one,
        // fall back to the origin rather than to whatever .env last held.
        if (blank(config('filesystems.disks.spaces.url'))) {
            Config::set('filesystems.disks.spaces.url', config('filesystems.disks.spaces.endpoint'));
        }

        $disk = Setting::get('media_disk');

        // Only a disk that exists, and — for Spaces — only one that has been
        // filled in. A half-configured Spaces would send every new upload into
        // a failing write, which is worse than staying local.
        if ($disk === self::SPACES_DISK && self::spacesIsFilledIn()) {
            Config::set('filesystems.media', self::SPACES_DISK);

            return;
        }

        if ($disk === self::LOCAL_DISK) {
            Config::set('filesystems.media', self::LOCAL_DISK);
        }
    }

    /** What the panel should show as selected. */
    public static function selectedDisk(): string
    {
        return config('filesystems.media') === self::SPACES_DISK
            ? self::SPACES_DISK
            : self::LOCAL_DISK;
    }

    public static function spacesIsFilledIn(): bool
    {
        foreach (['key', 'secret', 'bucket', 'endpoint'] as $part) {
            if (blank(config('filesystems.disks.spaces.'.$part))) {
                return false;
            }
        }

        return true;
    }

    /**
     * The Spaces settings as the form should show them.
     *
     * The secret is never among them. It goes out of the database only into a
     * disk being built, never into a page — somebody screen-sharing this
     * screen to ask for help should not be handing over their key.
     *
     * @return array<string, ?string>
     */
    public static function formValues(): array
    {
        $values = [];

        foreach (array_keys(self::SPACES_KEYS) as $setting) {
            if ($setting === self::SECRET_KEY) {
                continue;
            }

            $values[$setting] = Setting::get($setting)
                ?? config(self::SPACES_KEYS[$setting]);
        }

        return $values;
    }

    /** Whether a secret is on file at all, without saying what it is. */
    public static function hasSecret(): bool
    {
        return filled(self::storedSpacesValue(self::SECRET_KEY))
            || filled(env('DO_SPACES_SECRET'));
    }

    /**
     * Writes a file to the given Spaces settings, reads it back and deletes it.
     *
     * Against a disk built on the spot rather than the configured one, so that
     * credentials can be proven **before** they are saved. Every other order
     * ends with somebody's uploads going into a bucket that rejects them.
     *
     * @param  array<string, ?string>  $candidate  the six spaces_* values
     * @return array{ok: bool, message: string}
     */
    public static function test(array $candidate): array
    {
        foreach (['spaces_key', 'spaces_secret', 'spaces_bucket', 'spaces_endpoint'] as $required) {
            if (blank($candidate[$required] ?? null)) {
                return [
                    'ok' => false,
                    'message' => 'Fill in the key, secret, bucket and endpoint first.',
                ];
            }
        }

        $path = 'health/connection-test-'.Str::random(12).'.txt';
        $body = 'Written by the Storage screen at '.now()->toIso8601String();

        try {
            $disk = self::buildDisk($candidate);

            $disk->put($path, $body);

            if ($disk->get($path) !== $body) {
                $disk->delete($path);

                return [
                    'ok' => false,
                    'message' => 'The file was written but came back different. Check that the bucket is not being served from a cache.',
                ];
            }

            $disk->delete($path);

            return [
                'ok' => true,
                'message' => 'Connected: wrote a file to the bucket, read it back and deleted it.',
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'message' => self::explain($e),
            ];
        }
    }

    /**
     * A disk built from values that are not (yet) the configured ones.
     *
     * @param  array<string, ?string>  $candidate
     */
    public static function buildDisk(array $candidate): Filesystem
    {
        // Resolved rather than constructed, so a test can prove the
        // switch-and-save path without a bucket to talk to.
        return app(FilesystemManager::class)->build([
            'driver' => 's3',
            'key' => $candidate['spaces_key'],
            'secret' => $candidate['spaces_secret'],
            'region' => $candidate['spaces_region'] ?: 'blr1',
            'bucket' => $candidate['spaces_bucket'],
            'endpoint' => $candidate['spaces_endpoint'],
            'url' => $candidate['spaces_cdn_endpoint'] ?: $candidate['spaces_endpoint'],
            'use_path_style_endpoint' => false,
            'visibility' => 'public',
            // The point of this disk is to hear about failures.
            'throw' => true,
        ]);
    }

    /**
     * Saves the settings, and says plainly when it refused to.
     *
     * A blank secret means "leave the one on file alone", not "clear it" —
     * otherwise every save from a form that cannot show the secret would wipe
     * it.
     *
     * @param  array<string, mixed>  $state
     * @return array{ok: bool, message: string}
     */
    public static function save(array $state): array
    {
        $wantsSpaces = ($state['media_disk'] ?? self::LOCAL_DISK) === self::SPACES_DISK;

        $candidate = self::candidateFrom($state);

        if ($wantsSpaces) {
            $result = self::test($candidate);

            if (! $result['ok']) {
                return [
                    'ok' => false,
                    'message' => 'Not switched — uploads are still going to this server. '.$result['message'],
                ];
            }
        }

        foreach (self::SPACES_KEYS as $setting => $path) {
            $value = $candidate[$setting] ?? null;

            Setting::set(
                $setting,
                filled($value) ? self::encode($setting, $value) : null,
                $setting === self::SECRET_KEY ? 'encrypted' : 'string',
            );
        }

        Setting::set('media_disk', $wantsSpaces ? self::SPACES_DISK : self::LOCAL_DISK);

        // The rest of this request is still running on the old config.
        self::apply();

        return [
            'ok' => true,
            'message' => $wantsSpaces
                ? 'New uploads now go to DigitalOcean Spaces. Files already on this server stay where they are and keep working.'
                : 'New uploads now go to this server. Files already on Spaces stay there and keep working.',
        ];
    }

    /**
     * The six values as they should be saved, with the secret resolved.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, ?string>
     */
    public static function candidateFrom(array $state): array
    {
        $candidate = [];

        foreach (array_keys(self::SPACES_KEYS) as $setting) {
            $candidate[$setting] = filled($state[$setting] ?? null)
                ? trim((string) $state[$setting])
                : null;
        }

        // Blank means unchanged, for the one field the form cannot show.
        if (blank($candidate[self::SECRET_KEY])) {
            $candidate[self::SECRET_KEY] = self::storedSpacesValue(self::SECRET_KEY)
                ?: env('DO_SPACES_SECRET');
        }

        return $candidate;
    }

    // --- Storing the secret ---

    protected static function encode(string $setting, string $value): string
    {
        return $setting === self::SECRET_KEY
            ? Crypt::encryptString($value)
            : $value;
    }

    /**
     * A stored setting, decrypted where it needs to be.
     *
     * Decryption fails after APP_KEY is rotated, and that must not take the
     * site down — a null here means Spaces is treated as unconfigured, which
     * keeps uploads on the local disk and shows on the Storage screen as a
     * missing secret rather than a 500 on every page.
     */
    protected static function storedSpacesValue(string $setting): ?string
    {
        $stored = Setting::get($setting);

        if (blank($stored)) {
            return null;
        }

        if ($setting !== self::SECRET_KEY) {
            return (string) $stored;
        }

        try {
            return Crypt::decryptString((string) $stored);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Turns an SDK exception into something worth reading.
     *
     * The AWS SDK's own messages run to several lines of request ids and XML,
     * and the part that matters — the wrong key, the missing bucket — is in
     * the middle of it.
     */
    protected static function explain(Throwable $e): string
    {
        /*
         * Down the chain, not just the top.
         *
         * Flysystem wraps the SDK exception and the SDK wraps the HTTP one, so
         * the sentence worth reading — the wrong key, the host that does not
         * resolve — is two levels below the message that reaches here.
         */
        $message = '';

        for ($level = $e, $depth = 0; $level !== null && $depth < 5; $level = $level->getPrevious(), $depth++) {
            $message .= ' '.$level->getMessage();
        }

        return match (true) {
            str_contains($message, 'InvalidAccessKeyId') => 'That access key does not exist. Check the key, and that it belongs to this Spaces region.',
            str_contains($message, 'SignatureDoesNotMatch') => 'The secret does not match the key. Re-copy it — a trailing space is enough to break it.',
            str_contains($message, 'NoSuchBucket') => 'There is no bucket by that name in this region.',
            str_contains($message, 'AccessDenied') => 'The key is valid but not allowed to write here. It needs read and write access to this bucket.',
            str_contains($message, 'cURL error 6'),
            str_contains($message, 'Could not resolve host'),
            str_contains($message, 'name lookup') => 'That endpoint does not resolve. It should look like https://blr1.digitaloceanspaces.com.',
            str_contains($message, 'cURL error 7'),
            str_contains($message, 'Connection refused') => 'Nothing answered at that endpoint. Check it for a typo.',
            str_contains($message, 'cURL error 28'),
            str_contains($message, 'timed out') => 'The bucket did not answer in time. If this host blocks outbound connections, Spaces cannot be used from it.',
            str_contains($message, 'cURL error 60'),
            str_contains($message, 'certificate') => 'The endpoint answered but its certificate could not be verified. Check that the endpoint is https and spelled correctly.',
            // 56 with a CONNECT tunnel is a proxy in the way, which on a
            // managed host means outbound traffic is filtered rather than
            // that anything about the credentials is wrong.
            str_contains($message, 'CONNECT tunnel failed'),
            str_contains($message, 'cURL error 56') => 'This server would not let the request out — something between it and the internet blocked the connection. Ask the host whether outbound HTTPS to digitaloceanspaces.com is allowed.',
            preg_match('/cURL error (\d+)/', $message, $matches) === 1 => 'Could not connect to the bucket from this server (cURL error '.$matches[1].'). Check the endpoint, and whether this host allows outbound connections.',
            default => 'Could not reach the bucket: '.Str::limit(trim(preg_replace('/\s+/', ' ', $message)), 160),
        };
    }
}
