<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Marketing 3.1.
 *
 * F5 — marketing_campaigns.prepare_cursor: NULL = recipients were
 * expanded during approval (small audience). A number = large audience
 * expanded in the background by the worker; it is the last contact id
 * processed (keyset paging), so expansion resumes after a timeout/crash.
 *
 * F7 — marketing_messages.billed_units: SMS parts already charged to the
 * organization's platform credit for this message (NULL = not charged).
 * A recovered or retried message never pays twice.
 *
 * Additive columns only; rollback drops them.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE marketing_campaigns ADD COLUMN prepare_cursor INT UNSIGNED NULL AFTER total_recipients');
        $pdo->exec('ALTER TABLE marketing_messages ADD COLUMN billed_units SMALLINT UNSIGNED NULL AFTER attempts');
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE marketing_messages DROP COLUMN billed_units');
        $pdo->exec('ALTER TABLE marketing_campaigns DROP COLUMN prepare_cursor');
    }
};
