<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Generic action rate limiting (ActionRateLimiter): one row per allowed
 * hit of a bucket (e.g. "messaging.test_send") for a subject
 * (e.g. "user:12", "org:3"). Rows older than the longest window are
 * purged opportunistically. Additive; rollback drops the table (limits
 * simply start counting from zero again).
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE rate_limit_hits (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                bucket VARCHAR(100) NOT NULL,
                subject VARCHAR(100) NOT NULL,
                created_at DATETIME NOT NULL,
                KEY idx_rate_limit_hits_lookup (bucket, subject, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS rate_limit_hits');
    }
};
