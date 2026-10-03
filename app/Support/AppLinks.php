<?php

namespace App\Support;

/**
 * The files phones read to open darshansaathi.com/temples/… links in the
 * installed app: Android's Digital Asset Links and iOS's
 * apple-app-site-association. Both come from Admin → App control, so a new
 * signing key is a settings change, not a deploy.
 */
final class AppLinks
{
    public const DEFAULT_ANDROID_PACKAGE = 'com.darshansaathi.templevisit';

    /** @return array<int, array<string, mixed>> */
    public static function android(): array
    {
        $fingerprints = collect(preg_split('/[\s,]+/', (string) setting('app_android_sha256')))
            ->map(fn (string $f): string => strtoupper(trim($f)))
            ->filter(fn (string $f): bool => preg_match('/^([0-9A-F]{2}:){31}[0-9A-F]{2}$/', $f) === 1)
            ->values()
            ->all();

        if ($fingerprints === []) {
            return [];
        }

        return [[
            'relation' => ['delegate_permission/common.handle_all_urls'],
            'target' => [
                'namespace' => 'android_app',
                'package_name' => (string) (setting('app_android_package') ?: self::DEFAULT_ANDROID_PACKAGE),
                'sha256_cert_fingerprints' => $fingerprints,
            ],
        ]];
    }

    /** @return array<string, mixed> */
    public static function apple(): array
    {
        $appId = trim((string) setting('app_ios_app_id'));

        return [
            'applinks' => [
                'apps' => [],
                'details' => $appId === '' ? [] : [[
                    'appIDs' => [$appId],
                    'components' => [['/' => '/temples/*', 'comment' => 'A temple page opens that temple in the app.']],
                    'paths' => ['/temples/*'],
                ]],
            ],
        ];
    }
}
