<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Module activation requests (Modules & Features catalog): an organization
 * admin asks for a module; a Super Admin approves (through the existing
 * per-organization entitlement, ModuleService::setOrganizationEntitlement)
 * or rejects. A request never grants access by itself.
 *
 * status: pending | approved | rejected. Requests are never deleted (history).
 * pending_key = "{organization_id}:{module_id}" while pending, NULL once
 * reviewed — the UNIQUE index allows only ONE pending request per
 * organization and module (NULLs do not collide).
 * module_key keeps the requested key even if the module row changes.
 *
 * New table only; rollback drops it.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE module_activation_requests (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                module_id INT UNSIGNED NOT NULL,
                module_key VARCHAR(60) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                pending_key VARCHAR(40) NULL,
                requested_by_user_id INT UNSIGNED NULL,
                reviewed_by_user_id INT UNSIGNED NULL,
                reviewed_at DATETIME NULL,
                rejection_reason VARCHAR(500) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_module_requests_pending (pending_key),
                KEY idx_module_requests_status (status, created_at),
                KEY idx_module_requests_org_module (organization_id, module_id, id),
                CONSTRAINT fk_module_requests_org FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_module_requests_module FOREIGN KEY (module_id) REFERENCES modules (id) ON DELETE CASCADE,
                CONSTRAINT fk_module_requests_requested_by FOREIGN KEY (requested_by_user_id) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT fk_module_requests_reviewed_by FOREIGN KEY (reviewed_by_user_id) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS module_activation_requests');
    }
};
