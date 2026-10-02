<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Same pattern as 0015 (new migration granting new permission keys to
 * existing system roles, never editing the already-applied seed
 * migration). org_admin gets everything at organization scope.
 * member gets view/create at organization scope but edit/convert at
 * own scope — they can see and add records, but can only edit ones
 * they own — matching the Phase 0 §17 Sales Agent/Sales Manager
 * example (deals.view=OWN vs ORGANIZATION) applied to Contacts/
 * Companies/Leads instead.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $orgAdminId = $this->roleId($pdo, 'org_admin');
        $memberId = $this->roleId($pdo, 'member');

        if ($orgAdminId !== null) {
            foreach ([
                'contacts.view', 'contacts.create', 'contacts.edit', 'contacts.delete', 'contacts.export',
                'companies.view', 'companies.create', 'companies.edit', 'companies.delete',
                'leads.view', 'leads.create', 'leads.edit', 'leads.delete', 'leads.assign', 'leads.convert',
                'custom_fields.manage',
            ] as $key) {
                $this->grant($pdo, $orgAdminId, $key, 'organization');
            }
        }

        if ($memberId !== null) {
            $this->grant($pdo, $memberId, 'contacts.view', 'organization');
            $this->grant($pdo, $memberId, 'contacts.create', 'organization');
            $this->grant($pdo, $memberId, 'contacts.edit', 'own');
            $this->grant($pdo, $memberId, 'companies.view', 'organization');
            $this->grant($pdo, $memberId, 'companies.create', 'organization');
            $this->grant($pdo, $memberId, 'companies.edit', 'own');
            $this->grant($pdo, $memberId, 'leads.view', 'organization');
            $this->grant($pdo, $memberId, 'leads.create', 'organization');
            $this->grant($pdo, $memberId, 'leads.edit', 'own');
            $this->grant($pdo, $memberId, 'leads.convert', 'own');
        }
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec(
            "DELETE FROM role_permissions WHERE permission_key IN (
                'contacts.view', 'contacts.create', 'contacts.edit', 'contacts.delete', 'contacts.export',
                'companies.view', 'companies.create', 'companies.edit', 'companies.delete',
                'leads.view', 'leads.create', 'leads.edit', 'leads.delete', 'leads.assign', 'leads.convert',
                'custom_fields.manage'
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
