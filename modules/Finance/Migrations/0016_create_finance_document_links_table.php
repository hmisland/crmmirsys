<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Links a proforma or an invoice to a record of another module — today a
 * contract (docs/contract-finance-design.md). Finance owns the link; the
 * other module never learns about it.
 *
 * Purely additive: proformas / invoices / payments are not altered.
 * - document_id has no FK because it points at one of two tables
 *   (proformas or invoices, both this module's); the services delete the
 *   link together with a (draft) document.
 * - linked_id has NO FK: the other module may not be installed. It is
 *   validated through RecordTypeRegistry::find() when the link is made;
 *   linked_label is the snapshot shown when that module is off or the
 *   record is gone.
 * - UNIQUE (organization_id, document_type, document_id, linked_type): a document belongs
 *   to at most one contract; a contract may have many documents (D3).
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE finance_document_links (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                document_type ENUM('proforma', 'invoice') NOT NULL,
                document_id INT UNSIGNED NOT NULL,
                linked_type VARCHAR(50) NOT NULL,
                linked_id INT UNSIGNED NOT NULL,
                linked_label VARCHAR(255) NOT NULL DEFAULT '',
                created_by_user_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_finance_document_links_document (organization_id, document_type, document_id, linked_type),
                KEY idx_finance_document_links_target (organization_id, linked_type, linked_id),
                CONSTRAINT fk_finance_document_links_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_finance_document_links_created_by FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS finance_document_links');
    }
};
