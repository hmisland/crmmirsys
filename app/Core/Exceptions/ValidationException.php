<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

final class ValidationException extends HttpException
{
    /**
     * @param array<string, string[]> $errors field => list of messages
     */
    public function __construct(public readonly array $errors, string $message = 'Validation failed.')
    {
        parent::__construct(422, $message, 'validation_failed');
    }
}
