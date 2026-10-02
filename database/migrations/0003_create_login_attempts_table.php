<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Backs LoginThrottle (Phase 0 §32/§60: rate limiting on auth
 * endpoints). Deliberately NOT foreign-keyed to users — a failed
 * login for an email that doesn't exist must still be recorded (that
 * IS the attack this table defends against), so it can't reference a
 * user row that may not exist.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE login_attempts (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(255) NOT NULL,
                ip_address VARCHAR(45) NOT NULL,
                succeeded TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_login_attempts_email_time (email, created_at),
                KEY idx_login_attempts_ip_time (ip_address, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS login_attempts');
    }
};
