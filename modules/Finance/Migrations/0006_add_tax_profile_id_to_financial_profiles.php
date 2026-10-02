<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE financial_profiles ADD COLUMN tax_profile_id INT UNSIGNED NULL AFTER tax_jurisdiction_id');
        $pdo->exec(
            'ALTER TABLE financial_profiles ADD CONSTRAINT fk_financial_profiles_tax_profile
             FOREIGN KEY (tax_profile_id) REFERENCES tax_profiles (id) ON DELETE SET NULL'
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE financial_profiles DROP FOREIGN KEY fk_financial_profiles_tax_profile');
        $pdo->exec('ALTER TABLE financial_profiles DROP COLUMN tax_profile_id');
    }
};
