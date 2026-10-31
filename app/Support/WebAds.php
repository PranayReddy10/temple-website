<?php

namespace App\Support;

use Illuminate\Support\Facades\Auth;

/**
 * Google AdSense on the website (darshansaathi.com), set under
 * Admin → Monetisation → Ads → Website ads.
 *
 * Shown only on public reading pages that ask for them (home, temple lists,
 * temple pages, festival pages): never on sign-in, account, booking, hundi,
 * ticket or payment pages, never on the admin host, and never to a signed-in
 * devotee whose plan removes ads, as in the app.
 */
final class WebAds
{
    public const CLIENT = '/^ca-pub-\d{10,20}$/';

    /** The publisher id as AdSense gives it: ca-pub-1234567890123456. */
    public static function client(): ?string
    {
        $id = trim((string) setting('adsense_client'));
        if (preg_match('/^pub-\d{10,20}$/', $id) === 1) {
            $id = 'ca-'.$id;
        }

        return preg_match(self::CLIENT, $id) === 1 ? $id : null;
    }

    /** Whether ads may show to this visitor on this website request. */
    public static function enabled(): bool
    {
        if (! (bool) setting('ads_web_enabled', null, false) || self::client() === null) {
            return false;
        }
        if (! Seo::onWebsite(request())) {
            return false;
        }

        $devotee = Auth::guard('devotee_web')->user();

        return ! ($devotee?->entitlements()['no_ads'] ?? false);
    }

    /** Google's test ads instead of paid ones, while setting up. */
    public static function testMode(): bool
    {
        return (bool) setting('adsense_test_mode', null, false);
    }

    /** An ad unit's slot id for a place on the page, or null when none is set. */
    public static function slot(string $place): ?string
    {
        $slot = trim((string) setting('adsense_slot_'.$place));

        return preg_match('/^\d{6,20}$/', $slot) === 1 ? $slot : null;
    }

    /**
     * ads.txt: who may sell this site's ad space. AdSense warns, and earns
     * less, until it lists the publisher id. Extra lines (other networks)
     * come from the setting as they are.
     */
    public static function adsTxt(): string
    {
        $lines = [];
        if ($client = self::client()) {
            $lines[] = 'google.com, '.substr($client, 3).', DIRECT, f08c47fec0942fa0';
        }
        foreach (preg_split('/\R/', (string) setting('ads_txt_extra')) ?: [] as $line) {
            if (trim($line) !== '') {
                $lines[] = trim($line);
            }
        }

        return implode("\n", array_unique($lines))."\n";
    }
}
