<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE contacts ADD COLUMN date_of_birth DATE NULL AFTER gender');
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE contacts DROP COLUMN date_of_birth');
    }
};
