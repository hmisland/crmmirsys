<?php

declare(strict_types=1);

namespace App\Core\Middleware;

use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Security\Csrf;

/**
 * Applied to every state-changing web route (POST/PUT/PATCH/DELETE).
 * API routes authenticate differently (token-based, added when Auth
 * lands in Phase 2) and are exempt — this middleware is only ever
 * attached to routes/web.php entries.
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        if (in_array($request->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $token = (string) $request->input('_csrf', '');

            if (!Csrf::verify($token)) {
                throw new HttpException(419, 'Your session has expired. Please refresh and try again.', 'csrf_mismatch');
            }
        }

        return $next($request);
    }
}
