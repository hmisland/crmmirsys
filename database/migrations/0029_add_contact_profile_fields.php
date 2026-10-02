<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * `email`/`phone` on contacts stay as-is — they now represent the
 * contact's PRIMARY email/phone, kept in sync from contact_emails/
 * contact_phones (next two migrations) whenever the user marks one as
 * primary. Existing code (search, CSV import/export, duplicate
 * detection) keeps working against these two columns unchanged;
 * multi-value support is additive, not a breaking schema change.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec("ALTER TABLE contacts ADD COLUMN gender ENUM('male', 'female') NULL AFTER last_name");
        $pdo->exec('ALTER TABLE contacts ADD COLUMN province VARCHAR(100) NULL AFTER city');
        $pdo->exec('ALTER TABLE contacts ADD COLUMN description TEXT NULL AFTER source');
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE contacts DROP COLUMN description');
        $pdo->exec('ALTER TABLE contacts DROP COLUMN province');
        $pdo->exec('ALTER TABLE contacts DROP COLUMN gender');
    }
};
