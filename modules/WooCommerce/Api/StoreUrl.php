<?php

declare(strict_types=1);

namespace Modules\WooCommerce\Api;

use App\Infrastructure\Config\Config;

/**
 * Validates a store URL entered by an organization admin before the
 * server ever calls it: https only, a public host name (not an IP in a
 * private/reserved range, not "localhost"), no credentials, query or
 * fragment. Plain http and local hosts are accepted only when
 * app.env = local (development stores). Returns the normalized base
 * URL (no trailing slash) or null.
 */
final class StoreUrl
{
    public static function normalize(string $url): ?string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $local = Config::get('app.env') === 'local';
        if ($scheme !== 'https' && !($local && $scheme === 'http')) {
            return null;
        }
        if (!$local && !self::publicHost($host)) {
            return null;
        }

        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $path = rtrim((string) ($parts['path'] ?? ''), '/');

        return $scheme . '://' . $host . $port . $path;
    }

    private static function publicHost(string $host): bool
    {
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return false;
        }
        $ip = trim($host, '[]');
        if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
            return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        return str_contains($host, '.');
    }
}
