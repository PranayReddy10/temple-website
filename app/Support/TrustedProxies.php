<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Turns the TRUSTED_PROXIES environment value into what Laravel's
 * trustProxies() expects.
 *
 * Extracted from bootstrap/app.php so the decision is directly testable.
 * Testing it through the environment variable instead proved unreliable:
 * Laravel's env repository is a process-wide static, so the first test to
 * boot the application fixes the value for every later test in that process,
 * and the result depended on whether the local .env happened to contain the
 * key at all.
 */
class TrustedProxies
{
    /** The forwarded headers believed from a trusted proxy: never the host. */
    public const HEADERS = Request::HEADER_X_FORWARDED_FOR
        | Request::HEADER_X_FORWARDED_PROTO
        | Request::HEADER_X_FORWARDED_PORT
        | Request::HEADER_X_FORWARDED_AWS_ELB;

    /**
     * Hosts the app answers on, as patterns for trustHosts(): the admin host
     * from APP_URL and the website, each with its subdomains.
     *
     * @return array<int, string>
     */
    public static function hosts(): array
    {
        $hosts = [];
        foreach ([config('app.url'), config('brand.website')] as $url) {
            $host = parse_url((string) $url, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                $hosts[] = '^(.+\\.)?'.preg_quote(strtolower($host)).'$';
            }
        }

        return array_values(array_unique($hosts));
    }

    /**
     * @return string|array<int, string>|null
     *                                        '*' to trust any proxy, a list of addresses, or null to trust none.
     */
    public static function from(?string $value): string|array|null
    {
        $value = trim((string) $value);

        // Unset, empty, or explicitly disabled: trust nothing. This is the
        // default that ships in .env.example, and it must stay safe — a
        // trusted forwarded header lets anyone who can reach the origin
        // directly spoof their IP and the request scheme.
        if ($value === '' || strtolower($value) === 'false' || $value === '0') {
            return null;
        }

        if ($value === '*') {
            return '*';
        }

        $proxies = array_values(array_filter(
            array_map('trim', explode(',', $value)),
            fn (string $proxy): bool => $proxy !== '',
        ));

        return $proxies === [] ? null : $proxies;
    }
}
