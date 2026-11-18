<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Core\Request;

/**
 * Pure helpers for Bearer access tokens: reading the credential from a
 * request, generating a new token, hashing it, and deciding which requests
 * are "stateless" (must not start a PHP session).
 *
 * A user Bearer token is only ever honoured on /api/* paths. Other routes
 * legitimately receive unrelated "Authorization: Bearer ..." headers (e.g.
 * the email-provider webhooks under /webhooks/*, which compare the header to
 * their own stored secret), and those must never be interpreted as a user
 * login nor change how the request is authenticated.
 */
final class BearerToken
{
    /** Recognisable prefix: lets secret scanners and humans spot a leaked CRM token. */
    public const PREFIX = 'crmat_';

    /** POST here exchanges credentials for a token; any Authorization header is ignored. */
    public const ISSUE_PATH = '/api/v1/auth/token';

    private const RANDOM_BYTES = 32;

    /**
     * The raw Authorization header, from wherever the web server put it:
     * PHP's HTTP_AUTHORIZATION, or REDIRECT_HTTP_AUTHORIZATION — Apache/
     * LiteSpeed rename the variable when a RewriteRule's internal redirect
     * has run (this project rewrites twice: / -> /public -> index.php).
     * Never read from the query string.
     */
    public static function authorizationHeader(Request $request): ?string
    {
        $candidates = [
            $request->header('AUTHORIZATION'),
            $request->server('HTTP_AUTHORIZATION'),
            $request->server('REDIRECT_HTTP_AUTHORIZATION'),
        ];

        foreach ($candidates as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * null  = no Bearer credential was presented (no header, or another scheme).
     * ''    = scheme "Bearer" with no token (still a presented credential: invalid).
     * other = the token text, unvalidated.
     */
    public static function credential(Request $request): ?string
    {
        $header = self::authorizationHeader($request);

        if ($header === null || preg_match('/^Bearer(?:\s+(.*))?$/is', $header, $m) !== 1) {
            return null;
        }

        return trim($m[1] ?? '');
    }

    public static function isTokenIssuance(Request $request): bool
    {
        return $request->method === 'POST' && rtrim($request->path, '/') === self::ISSUE_PATH;
    }

    /** True when this request must be authenticated by its Bearer token and by nothing else. */
    public static function appliesTo(Request $request): bool
    {
        return $request->isApi()
            && !self::isTokenIssuance($request)
            && self::credential($request) !== null;
    }

    /**
     * Requests that never need the PHP session: Bearer-authenticated requests,
     * token issuance, and CORS preflights. Starting one would create a session
     * file and emit a Set-Cookie for nothing.
     */
    public static function isStateless(Request $request): bool
    {
        if (!$request->isApi()) {
            return false;
        }

        return $request->method === 'OPTIONS'
            || self::isTokenIssuance($request)
            || self::credential($request) !== null;
    }

    public static function generate(): string
    {
        return self::PREFIX . bin2hex(random_bytes(self::RANDOM_BYTES));
    }

    /** SHA-256 is enough here: the secret is 256 random bits, not a human password. */
    public static function hash(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    /** Cheap shape check so garbage never reaches the database. */
    public static function isWellFormed(string $rawToken): bool
    {
        return preg_match('/^' . preg_quote(self::PREFIX, '/') . '[0-9a-f]{' . (self::RANDOM_BYTES * 2) . '}$/', $rawToken) === 1;
    }
}
