<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

final class LoginAttemptRepository extends BaseRepository
{
    public function record(string $email, string $ip, bool $succeeded): void
    {
        $this->insertGetId('login_attempts', [
            'email' => $email,
            'ip_address' => $ip,
            'succeeded' => $succeeded ? 1 : 0,
        ]);
    }

    public function recentFailedCount(string $email, string $ip, int $windowMinutes): int
    {
        $cutoff = (new \DateTimeImmutable())->modify("-{$windowMinutes} minutes")->format('Y-m-d H:i:s');

        $row = $this->findOne(
            'SELECT COUNT(*) AS c FROM login_attempts
             WHERE succeeded = 0 AND created_at >= :cutoff AND (email = :email OR ip_address = :ip)',
            ['cutoff' => $cutoff, 'email' => $email, 'ip' => $ip]
        );

        return (int) ($row['c'] ?? 0);
    }
}
