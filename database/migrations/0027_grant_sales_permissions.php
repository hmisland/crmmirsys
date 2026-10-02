<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Same pattern as 0015/0026: a new migration granting new permission
 * keys, never editing an already-applied seed migration. Granting
 * these permissions does NOT by itself give access to Sales — that
 * still requires the module to be enabled (globally and for the
 * organization) per the Phase 0 §6 chain, enforced by ModuleMiddleware
 * independently of RBAC. A role can hold deals.view and still get a
 * 404 on every /deals route if Sales isn't enabled for that org.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $orgAdminId = $this->roleId($pdo, 'org_admin');
        $memberId = $this->roleId($pdo, 'member');

        if ($orgAdminId !== null) {
            foreach ([
                'deals.view', 'deals.create', 'deals.edit', 'deals.delete', 'deals.change_stage',
                'pipelines.manage',
                'tasks.view', 'tasks.create', 'tasks.edit', 'tasks.delete',
            ] as $key) {
                $this->grant($pdo, $orgAdminId, $key, 'organization');
            }
        }

        if ($memberId !== null) {
            $this->grant($pdo, $memberId, 'deals.view', 'organization');
            $this->grant($pdo, $memberId, 'deals.create', 'organization');
            $this->grant($pdo, $memberId, 'deals.edit', 'own');
            $this->grant($pdo, $memberId, 'deals.change_stage', 'own');
            $this->grant($pdo, $memberId, 'tasks.view', 'organization');
            $this->grant($pdo, $memberId, 'tasks.create', 'organization');
            $this->grant($pdo, $memberId, 'tasks.edit', 'own');
        }
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec(
            "DELETE FROM role_permissions WHERE permission_key IN (
                'deals.view', 'deals.create', 'deals.edit', 'deals.delete', 'deals.change_stage',
                'pipelines.manage',
                'tasks.view', 'tasks.create', 'tasks.edit', 'tasks.delete'
            )"
        );
    }

    private function roleId(\PDO $pdo, string $key): ?int
    {
        $stmt = $pdo->prepare("SELECT id FROM roles WHERE `key` = :key AND is_system = 1 LIMIT 1");
        $stmt->execute(['key' => $key]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    private function grant(\PDO $pdo, int $roleId, string $permissionKey, string $scope): void
    {
        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO role_permissions (role_id, permission_key, scope) VALUES (:role_id, :permission_key, :scope)'
        );
        $stmt->execute(['role_id' => $roleId, 'permission_key' => $permissionKey, 'scope' => $scope]);
    }
};
