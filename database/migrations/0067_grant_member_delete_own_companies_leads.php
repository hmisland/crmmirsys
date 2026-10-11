<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Same as 0066 for companies and leads: the system Member role may
 * delete the companies/leads it owns (scope `own`, matching its existing
 * `edit` = own), so per-row and bulk delete on those lists work for
 * everyone on their own records. Organization Admin keeps organization
 * scope. INSERT IGNORE keeps any scope an org already configured.
 */
return new class implements MigrationInterface {
    private const KEYS = ['companies.delete', 'leads.delete'];

    public function up(\PDO $pdo): void
    {
        $stmt = $pdo->prepare("SELECT id FROM roles WHERE `key` = 'member' AND is_system = 1 LIMIT 1");
        $stmt->execute();
        $roleId = $stmt->fetchColumn();

        if ($roleId === false) {
            return;
        }

        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO role_permissions (role_id, permission_key, scope) VALUES (:role_id, :permission_key, :scope)'
        );
        foreach (self::KEYS as $key) {
            $stmt->execute(['role_id' => $roleId, 'permission_key' => $key, 'scope' => 'own']);
        }
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec(
            "DELETE rp FROM role_permissions rp JOIN roles r ON r.id = rp.role_id
             WHERE r.`key` = 'member' AND r.is_system = 1 AND rp.scope = 'own'
               AND rp.permission_key IN ('companies.delete', 'leads.delete')"
        );
    }
};
