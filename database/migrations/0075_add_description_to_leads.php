<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Free-text description on a lead, entered on the create/edit form.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE leads ADD COLUMN description TEXT NULL AFTER source');
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE leads DROP COLUMN description');
    }
};
