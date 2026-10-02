<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

class HttpException extends \RuntimeException
{
    public function __construct(
        public readonly int $statusCode,
        string $message = '',
        public readonly ?string $errorCode = null,
    ) {
        parent::__construct($message);
    }
}
