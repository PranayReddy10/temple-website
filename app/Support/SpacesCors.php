<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * CORS on the DigitalOcean Space, so browsers may read its photos by script.
 *
 * A plain <img> needs nothing, which is why the /temples pages always showed
 * photos. The Flutter website and the admin panel's upload previews fetch
 * files by script, and a browser only allows that from another domain when
 * the Space answers with Access-Control-Allow-Origin. Spaces sends none until
 * a rule is set; this sets it through the same S3 API the uploads use, so
 * nobody has to find the setting in DigitalOcean's panel.
 */
final class SpacesCors
{
    /** The sites allowed to read the Space: the website and this server. */
    public static function origins(): array
    {
        return array_values(array_unique(array_filter([
            rtrim((string) config('brand.website'), '/'),
            rtrim((string) config('app.url'), '/'),
        ], fn (string $o): bool => str_starts_with($o, 'https://') || str_starts_with($o, 'http://'))));
    }

    /** @return array<string, mixed> the CORSConfiguration sent to the Space */
    public static function configuration(): array
    {
        return [
            'CORSRules' => [[
                'AllowedOrigins' => self::origins(),
                'AllowedMethods' => ['GET', 'HEAD'],
                'AllowedHeaders' => ['*'],
                'MaxAgeSeconds' => 3600,
            ]],
        ];
    }

    /** @return array{ok: bool, message: string} */
    public static function apply(): array
    {
        if (! MediaStorage::spacesIsFilledIn()) {
            return ['ok' => false, 'message' => 'Spaces is not set up on this page yet, so there is nothing to allow.'];
        }

        try {
            /** @var \Illuminate\Filesystem\AwsS3V3Adapter $disk */
            $disk = Storage::disk(MediaStorage::SPACES_DISK);
            $disk->getClient()->putBucketCors([
                'Bucket' => config('filesystems.disks.spaces.bucket'),
                'CORSConfiguration' => self::configuration(),
            ]);
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'The Space refused: '.$e->getMessage().' The access key needs permission to change the Space\'s settings; or add the rule by hand in DigitalOcean → Spaces → Settings → CORS.'];
        }

        return ['ok' => true, 'message' => 'The Space now lets '.implode(' and ', self::origins()).' load its photos. The CDN may take a few minutes to pick it up.'];
    }
}
