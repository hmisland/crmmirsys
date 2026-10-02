<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Infrastructure\Config\Config;
use App\Infrastructure\Repositories\LoginAttemptRepository;

final class LoginThrottle
{
    public function __construct(private readonly LoginAttemptRepository $attempts)
    {
    }

    public function tooManyAttempts(string $email, string $ip): bool
    {
        $max = (int) Config::get('auth.login_max_attempts', 5);
        $windowMinutes = (int) Config::get('auth.login_throttle_minutes', 15);

        return $this->attempts->recentFailedCount($email, $ip, $windowMinutes) >= $max;
    }

    public function recordFailure(string $email, string $ip): void
    {
        $this->attempts->record($email, $ip, false);
    }

    public function recordSuccess(string $email, string $ip): void
    {
        $this->attempts->record($email, $ip, true);
    }
}
