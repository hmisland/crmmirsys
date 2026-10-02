<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

/**
 * "You are not who you say you are / you're not logged in" (401) —
 * distinct from AuthorizationException ("you're logged in, but not
 * allowed to do this", 403/RBAC, Phase 3). Login failures, expired
 * sessions, and unverified-email blocks all throw this.
 */
final class AuthenticationException extends HttpException
{
    public function __construct(string $message = 'Authentication required.', ?string $errorCode = 'unauthenticated')
    {
        parent::__construct(401, $message, $errorCode);
    }
}
