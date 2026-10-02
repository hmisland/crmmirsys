<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Only meaningful for type='landline' rows (an office extension
 * number) — nullable and simply ignored/blank for mobile numbers.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE contact_phones ADD COLUMN extension VARCHAR(20) NULL AFTER number');
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE contact_phones DROP COLUMN extension');
    }
};
