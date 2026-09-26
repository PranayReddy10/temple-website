<?php

namespace App\Support;

use App\Models\Devotee;
use App\Models\Payment;
use App\Models\Setting;

/**
 * Everything the app asks the server before it shows anything: whether it
 * may run at all (maintenance, a version too old), how people may sign in,
 * whether and where to show ads, whether plans can be bought, and the public
 * Firebase ids for push. All of it is set from the admin panel.
 */
final class AppConfig
{
    /** Google's own test units, used while "test ads only" is on. */
    private const ADMOB_TEST = [
        'android' => ['native' => 'ca-app-pub-3940256099942544/2247696110', 'banner' => 'ca-app-pub-3940256099942544/6300978111'],
        'ios' => ['native' => 'ca-app-pub-3940256099942544/3986624511', 'banner' => 'ca-app-pub-3940256099942544/2934735716'],
    ];

    /** @return array<string, mixed> */
    public static function for(?string $platform, ?string $version, ?Devotee $devotee = null): array
    {
        $platform = in_array($platform, ['android', 'ios', 'web'], true) ? $platform : 'android';

        return [
            'maintenance' => self::maintenance(),
            'update' => self::update($platform, $version),
            'auth' => [
                'password' => (bool) setting('auth_password_enabled', null, true),
                'google' => [
                    'enabled' => (bool) setting('auth_google_enabled', null, false) && filled(setting('auth_google_server_client_id')),
                    'server_client_id' => setting('auth_google_server_client_id'),
                    'ios_client_id' => setting('auth_google_ios_client_id'),
                ],
                // Apple sign-in is an iPhone thing; the app shows it there only.
                'apple' => ['enabled' => (bool) setting('auth_apple_enabled', null, false)],
            ],
            'push' => self::push($platform),
            'ads' => self::ads($platform, $devotee),
            'payments' => self::payments($platform),
            'support_email' => setting('support_email', 'brand.support_email'),
        ];
    }

    /** @return array<string, mixed> */
    private static function maintenance(): array
    {
        return [
            'enabled' => (bool) setting('app_maintenance_enabled', null, false),
            'title' => setting('app_maintenance_title', null, 'We will be back shortly'),
            'message' => setting('app_maintenance_message', null, 'The app is being updated. Please try again in a little while.'),
            'until' => setting('app_maintenance_until'),
        ];
    }

    /** @return array<string, mixed> */
    private static function update(string $platform, ?string $version): array
    {
        $prefix = $platform === 'ios' ? 'app_ios' : 'app_android';
        $latest = setting($prefix.'_latest_version');
        $minimum = setting($prefix.'_min_version');
        $current = self::normalise($version);
        $min = self::normalise(is_string($minimum) ? $minimum : null);
        $max = self::normalise(is_string($latest) ? $latest : null);

        $required = $current !== null && $min !== null && version_compare($current, $min, '<');
        $available = $current !== null && $max !== null && version_compare($current, $max, '<');

        return [
            'latest_version' => $latest,
            'min_version' => $minimum,
            'available' => $available || $required,
            'required' => $required,
            'store_url' => setting($prefix.'_store_url'),
            'title' => setting('app_update_title', null, 'A new version is ready'),
            'message' => setting('app_update_message'),
        ];
    }

    /** @return array<string, mixed> */
    private static function push(string $platform): array
    {
        $key = $platform === 'ios' ? 'ios' : 'android';
        $options = [
            'project_id' => setting('firebase_project_id'),
            'messaging_sender_id' => setting('firebase_messaging_sender_id'),
            'api_key' => setting('firebase_'.$key.'_api_key'),
            'app_id' => setting('firebase_'.$key.'_app_id'),
            'ios_bundle_id' => $key === 'ios' ? setting('firebase_ios_bundle_id') : null,
        ];

        $complete = filled($options['project_id']) && filled($options['api_key']) && filled($options['app_id']) && filled($options['messaging_sender_id']);

        return [
            // Only the public ids: the service account never leaves the server.
            'enabled' => $platform !== 'web' && $complete && (bool) setting('push_enabled', null, false),
            'firebase' => $complete ? $options : null,
        ];
    }

    /** @return array<string, mixed> */
    private static function ads(string $platform, ?Devotee $devotee): array
    {
        $network = setting('ads_network', null, 'admob') === 'applovin_max' ? 'applovin_max' : 'admob';
        $test = (bool) setting('ads_test_mode', null, true);
        $os = $platform === 'ios' ? 'ios' : 'android';

        $units = $network === 'admob'
            ? ($test ? self::ADMOB_TEST[$os] : [
                'native' => setting('ads_admob_'.$os.'_native'),
                'banner' => setting('ads_admob_'.$os.'_banner'),
            ])
            : [
                'native' => setting('ads_applovin_'.$os.'_native'),
                'banner' => setting('ads_applovin_'.$os.'_banner'),
            ];

        $noAds = $devotee?->entitlements()['no_ads'] ?? false;

        return [
            // Off on the web, and for anyone whose plan removes them.
            'enabled' => $platform !== 'web' && ! $noAds && (bool) setting('ads_enabled', null, false),
            'network' => $network,
            'test_mode' => $test,
            'applovin_sdk_key' => $network === 'applovin_max' ? setting('ads_applovin_sdk_key') : null,
            'units' => $units,
            'list_interval' => max(3, (int) setting('ads_list_interval', null, 6)),
            'placements' => [
                'temple_detail' => (bool) setting('ads_temple_detail', null, true),
                'explore' => (bool) setting('ads_explore', null, true),
                'home' => (bool) setting('ads_home', null, true),
                'day_page' => (bool) setting('ads_day_page', null, false),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function payments(string $platform): array
    {
        $gateways = self::enabledGateways();
        $allowedHere = match ($platform) {
            'ios' => (bool) setting('payments_ios', null, false),
            'android' => (bool) setting('payments_android', null, true),
            default => true,
        };

        return [
            'enabled' => $allowedHere && $gateways !== [] && (bool) setting('payments_enabled', null, false),
            // Plans are still listed on iOS, so the app can say where to buy.
            'available_elsewhere' => ! $allowedHere && $gateways !== [] && (bool) setting('payments_enabled', null, false),
            'gateways' => array_map(fn (string $g): array => [
                'code' => $g,
                'name' => Payment::GATEWAYS[$g],
                // Paid through the gateway's own SDK in the app.
                'native' => in_array($g, \App\Http\Controllers\Api\V1\SubscriptionController::NATIVE_SDK, true),
            ], $gateways),
            'default_gateway' => in_array(setting('payments_default_gateway'), $gateways, true) ? setting('payments_default_gateway') : ($gateways[0] ?? null),
        ];
    }

    /** @return array<int, string> Gateways switched on and with credentials. */
    public static function enabledGateways(): array
    {
        $ready = [
            'razorpay' => filled(setting('payments_razorpay_key_id')) && Setting::secret('payments_razorpay_key_secret') !== null,
            'phonepe' => filled(setting('payments_phonepe_client_id')) && Setting::secret('payments_phonepe_client_secret') !== null,
            'cashfree' => filled(setting('payments_cashfree_app_id')) && Setting::secret('payments_cashfree_secret_key') !== null,
            'payu' => filled(setting('payments_payu_key')) && Setting::secret('payments_payu_salt') !== null,
        ];

        return array_values(array_filter(
            array_keys(Payment::GATEWAYS),
            fn (string $g): bool => $ready[$g] && (bool) setting('payments_'.$g.'_enabled', null, false),
        ));
    }

    /** "1.2" → "1.2.0"; anything that is not a version → null. */
    private static function normalise(?string $version): ?string
    {
        if ($version === null || preg_match('/^(\d+(?:\.\d+){0,3})/', trim($version), $m) !== 1) {
            return null;
        }

        $parts = explode('.', $m[1]);

        while (count($parts) < 3) {
            $parts[] = '0';
        }

        return implode('.', $parts);
    }
}
