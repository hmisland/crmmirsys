<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE contacts ADD COLUMN category_id INT UNSIGNED NULL AFTER company_id');
        $pdo->exec(
            'ALTER TABLE contacts ADD CONSTRAINT fk_contacts_category
             FOREIGN KEY (category_id) REFERENCES contact_categories (id) ON DELETE SET NULL'
        );
        $pdo->exec('CREATE INDEX idx_contacts_category ON contacts (category_id)');
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE contacts DROP FOREIGN KEY fk_contacts_category');
        $pdo->exec('ALTER TABLE contacts DROP COLUMN category_id');
    }
};
