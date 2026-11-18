<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Config\Config;

/**
 * Explicit-allowlist CORS for /api/* only, for a browser client (the PWA)
 * served from a different origin than the CRM — note that app.example.com and
 * example.com are different ORIGINS even though they are the same SITE, so a
 * subdomain PWA needs this; a PWA served from the CRM's own origin does not.
 *
 * Disabled unless CORS_ALLOWED_ORIGINS lists at least one origin. No wildcard
 * is ever emitted: an allowed request origin is echoed back exactly, together
 * with Allow-Credentials. CORS is a browser read-permission, not
 * authentication: it grants nothing that authentication and RBAC refuse.
 * Native clients (Android/iOS) are not subject to CORS and are unaffected.
 */
final class CorsPolicy
{
    private const ALLOWED_METHODS = 'GET, POST, PUT, PATCH, DELETE, OPTIONS';
    private const ALLOWED_HEADERS = 'Content-Type, Accept, Authorization, X-CSRF-Token';
    private const MAX_AGE_SECONDS = '600';

    /** @var string[] */
    private array $origins;

    /** @param array<int, string> $origins */
    public function __construct(array $origins)
    {
        $this->origins = self::sanitize($origins);
    }

    public static function fromConfig(): self
    {
        return new self(explode(',', (string) Config::get('cors.allowed_origins', '')));
    }

    /**
     * Keeps only well-formed http(s) origins (scheme + host [+ port], no path).
     * "*" and "null" can never match this pattern, so they are always dropped.
     *
     * @param array<int, string> $origins
     * @return string[]
     */
    public static function sanitize(array $origins): array
    {
        $clean = [];

        foreach ($origins as $origin) {
            $origin = rtrim(trim((string) $origin), '/');

            if (preg_match('#^https?://[A-Za-z0-9.-]+(:[0-9]{1,5})?$#i', $origin) === 1) {
                $clean[] = strtolower($origin);
            }
        }

        return array_values(array_unique($clean));
    }

    public function isEnabled(): bool
    {
        return $this->origins !== [];
    }

    public function isAllowedOrigin(?string $origin): bool
    {
        if ($origin === null || $origin === '') {
            return false;
        }

        return in_array(strtolower(rtrim($origin, '/')), $this->origins, true);
    }

    public function isPreflight(Request $request): bool
    {
        return $request->method === 'OPTIONS'
            && $request->isApi()
            && (string) $request->header('ORIGIN', '') !== ''
            && (string) $request->header('ACCESS-CONTROL-REQUEST-METHOD', '') !== '';
    }

    /** Answers a preflight. A disallowed origin gets a bare 204: no CORS headers, so the browser refuses. */
    public function preflight(Request $request): Response
    {
        $response = Response::html('', 204);
        $origin = (string) $request->header('ORIGIN', '');

        if ($this->isAllowedOrigin($origin)) {
            $response
                ->withHeader('Access-Control-Allow-Origin', $origin)
                ->withHeader('Access-Control-Allow-Credentials', 'true')
                ->withHeader('Access-Control-Allow-Methods', self::ALLOWED_METHODS)
                ->withHeader('Access-Control-Allow-Headers', self::ALLOWED_HEADERS)
                ->withHeader('Access-Control-Max-Age', self::MAX_AGE_SECONDS);
        }

        return $response->withHeader('Vary', 'Origin');
    }

    /** Adds CORS headers to a normal /api/* response when the request origin is allowed. */
    public function decorate(Request $request, Response $response): Response
    {
        if (!$this->isEnabled() || !$request->isApi()) {
            return $response;
        }

        $response->withHeader('Vary', 'Origin');
        $origin = (string) $request->header('ORIGIN', '');

        if ($this->isAllowedOrigin($origin)) {
            $response
                ->withHeader('Access-Control-Allow-Origin', $origin)
                ->withHeader('Access-Control-Allow-Credentials', 'true');
        }

        return $response;
    }
}
