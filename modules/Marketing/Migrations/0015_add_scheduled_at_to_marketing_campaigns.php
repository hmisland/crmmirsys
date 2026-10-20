<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Phase D — scheduled campaigns: marketing_campaigns.scheduled_at (UTC).
 * NULL = send as soon as approved (as before). A campaign approved with a
 * time is queued as usual (audience frozen at approval) and the worker
 * starts sending only once scheduled_at has passed.
 *
 * Additive column + index; rollback drops them.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE marketing_campaigns ADD COLUMN scheduled_at DATETIME NULL AFTER queued_at, ADD INDEX idx_marketing_campaigns_scheduled (status, scheduled_at)');
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE marketing_campaigns DROP INDEX idx_marketing_campaigns_scheduled, DROP COLUMN scheduled_at');
    }
};
