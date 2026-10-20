<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Phase E — test invitations: an administrator sends a survey invitation
 * to their own number/email to try the flow. is_test = 1 marks it: no
 * order, no contact, no retries, shown as "Test", and answering it does
 * not create a survey response (statistics and follow-ups stay clean).
 *
 * Additive column; rollback drops it.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE cx_invitations ADD COLUMN is_test TINYINT(1) NOT NULL DEFAULT 0 AFTER source');
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE cx_invitations DROP COLUMN is_test');
    }
};
