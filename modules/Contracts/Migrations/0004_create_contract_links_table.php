<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Links from a contract to records of other modules (a deal today; an
 * invoice or project later). Deliberately NO foreign key to those
 * tables: the other module may not be installed, and Contracts must
 * install and run with Core alone. linked_type is a RecordTypeRegistry
 * key; linked_id is validated through RecordTypeRegistry::find() when
 * the link is made. linked_label is a snapshot shown when the other
 * module is later disabled or the record is gone.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE contract_links (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                contract_id INT UNSIGNED NOT NULL,
                linked_type VARCHAR(50) NOT NULL,
                linked_id INT UNSIGNED NOT NULL,
                linked_label VARCHAR(255) NOT NULL DEFAULT '',
                created_by_user_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_contract_links (contract_id, linked_type, linked_id),
                KEY idx_contract_links_target (organization_id, linked_type, linked_id),
                CONSTRAINT fk_contract_links_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_contract_links_contract FOREIGN KEY (contract_id) REFERENCES contracts (id) ON DELETE CASCADE,
                CONSTRAINT fk_contract_links_created_by FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS contract_links');
    }
};
