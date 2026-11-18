<?php

declare(strict_types=1);

namespace App\Core\Middleware;

use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Security\Csrf;

/**
 * Protects every state-changing route (POST/PUT/PATCH/DELETE) whose caller
 * is identified by the browser session cookie — the only credential a
 * foreign site can make a browser attach on its own.
 *
 * The token is accepted from the `_csrf` field (HTML forms, existing
 * clients) or from the `X-CSRF-Token` header (fetch/XHR from the PWA; obtain
 * the token from GET /api/v1/auth/csrf).
 *
 * The ONLY exemption is a request that Application has already authenticated
 * through a valid Bearer access token (attribute auth_method === 'bearer'):
 * such a request is not cookie-authenticated, so there is nothing for a
 * cross-site request to ride on. A request merely carrying an Authorization
 * header is not exempt, and neither is one whose Bearer token was rejected
 * (that request never reaches this middleware: it is answered 401 first).
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        $needsCheck = in_array($request->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)
            && $request->attribute('auth_method') !== 'bearer';

        if ($needsCheck) {
            $token = (string) $request->input('_csrf', '');

            if ($token === '') {
                $token = (string) $request->header('X-CSRF-TOKEN', '');
            }

            if (!Csrf::verify($token)) {
                throw new HttpException(419, 'Your session has expired. Please refresh and try again.', 'csrf_mismatch');
            }
        }

        return $next($request);
    }
}
