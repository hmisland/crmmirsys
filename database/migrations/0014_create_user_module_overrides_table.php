<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Per Phase 0 §7: an optional per-user override on top of the
 * organization's entitlement — e.g. an org has Wallet enabled
 * org-wide, but one specific user is explicitly denied it. No row
 * means "no override, defer to the organization's setting" (per
 * Phase 0 §6's chain). UI for managing these is deferred past this
 * phase (see docs/phase4) — the data model and resolution logic
 * (ModuleService::isEnabledForUser()) are complete and usable via
 * the Service/API now.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE user_module_overrides (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                module_id INT UNSIGNED NOT NULL,
                enabled TINYINT(1) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_user_module (organization_id, user_id, module_id),
                CONSTRAINT fk_user_module_overrides_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_user_module_overrides_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
                CONSTRAINT fk_user_module_overrides_module FOREIGN KEY (module_id) REFERENCES modules (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS user_module_overrides');
    }
};
