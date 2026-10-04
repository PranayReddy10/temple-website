<?php

namespace App\Support\Http;

use Closure;

/**
 * Where a server-side fetch of an address someone else typed may go: the
 * public internet only. A temple team chooses its website's address, and the
 * server reads it, so an address that leads to this machine (127.0.0.1), the
 * hosting network (10.x, 192.168.x), or a cloud metadata service
 * (169.254.169.254) is refused, directly, through a DNS name or a redirect.
 */
final class PublicAddress
{
    /**
     * Swapped in tests, where no DNS is available.
     *
     * @var (Closure(string): list<string>)|null
     */
    public static ?Closure $resolveUsing = null;

    /** The public IP to connect to for this URL's host, or null when it may not be fetched. */
    public static function for(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }
        $host = trim($host, '[]');

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : self::resolve(strtolower($host));
        // Every address the name has must be public: otherwise the one
        // connected to could be the private one.
        if ($ips === []) {
            return null;
        }
        foreach ($ips as $ip) {
            if (! self::isPublic($ip)) {
                return null;
            }
        }

        return $ips[0];
    }

    public static function isPublic(string $ip): bool
    {
        // IPv4-mapped IPv6 (::ffff:127.0.0.1) is judged as the IPv4 inside.
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m)) {
            $ip = $m[1];
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false
            // Carrier-grade NAT, not covered by the flags above.
            && ! (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && str_starts_with($ip, '100.') && (int) explode('.', $ip)[1] >= 64 && (int) explode('.', $ip)[1] <= 127);
    }

    /** @return list<string> */
    private static function resolve(string $host): array
    {
        // This machine, whatever DNS would say.
        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return [];
        }
        if (self::$resolveUsing !== null) {
            return (self::$resolveUsing)($host);
        }
        $ips = [];
        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($ip)) {
                $ips[] = $ip;
            }
        }
        if ($ips === []) {
            $ips = @gethostbynamel($host) ?: [];
        }

        return array_values(array_unique($ips));
    }
}
