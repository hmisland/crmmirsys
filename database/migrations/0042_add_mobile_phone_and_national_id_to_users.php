<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Core identity fields, not tied to any module — `mobile_phone` in
 * particular is now the single source of truth for a user's phone
 * number (the Notification module's SMS dispatch reads this instead
 * of maintaining its own separate copy, avoiding two different
 * "phone number" fields telling the user different things).
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE users ADD COLUMN mobile_phone VARCHAR(20) NULL AFTER last_name');
        $pdo->exec('ALTER TABLE users ADD COLUMN national_id VARCHAR(20) NULL AFTER mobile_phone');
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE users DROP COLUMN national_id');
        $pdo->exec('ALTER TABLE users DROP COLUMN mobile_phone');
    }
};
