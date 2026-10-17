<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Phase 2: a campaign that targets a segment freezes the segment's
 * definition at approval (dynamic: the validated rules; static: the
 * segment id + approval time), so background preparation of a large
 * audience is not changed by a later edit or deletion of the segment.
 * NULL for every other audience type and for campaigns approved before.
 * Additive; rollback drops the column.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE marketing_campaigns ADD COLUMN audience_snapshot TEXT NULL AFTER audience_ids');
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE marketing_campaigns DROP COLUMN audience_snapshot');
    }
};
