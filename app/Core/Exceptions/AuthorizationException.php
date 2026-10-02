<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

final class AuthorizationException extends HttpException
{
    public function __construct(string $message = 'You do not have permission to do that.')
    {
        parent::__construct(403, $message, 'forbidden');
    }
}
