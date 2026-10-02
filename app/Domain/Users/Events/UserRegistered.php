<?php

declare(strict_types=1);

namespace App\Domain\Users\Events;

use App\Core\Events\EventInterface;

final class UserRegistered implements EventInterface
{
    public function __construct(public readonly int $userId, public readonly string $email)
    {
    }
}
