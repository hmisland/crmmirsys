<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $orgAdminId = $this->roleId($pdo, 'org_admin');
        $memberId = $this->roleId($pdo, 'member');

        if ($orgAdminId !== null) {
            foreach ([
                'wallet.view', 'wallet.topup', 'wallet.transfer', 'wallet.withdraw_request',
                'wallet.transactions.view', 'wallet.adjust', 'wallet.withdrawals.manage', 'wallet.settings.manage',
            ] as $key) {
                $this->grant($pdo, $orgAdminId, $key, 'organization');
            }
        }

        // Members can only ever see/act on their OWN wallet — no
        // visibility into anyone else's balance or the org-wide
        // transaction ledger, and no admin actions.
        if ($memberId !== null) {
            $this->grant($pdo, $memberId, 'wallet.view', 'own');
            $this->grant($pdo, $memberId, 'wallet.topup', 'own');
            $this->grant($pdo, $memberId, 'wallet.transfer', 'own');
            $this->grant($pdo, $memberId, 'wallet.withdraw_request', 'own');
        }
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec(
            "DELETE FROM role_permissions WHERE permission_key IN (
                'wallet.view', 'wallet.topup', 'wallet.transfer', 'wallet.withdraw_request',
                'wallet.transactions.view', 'wallet.adjust', 'wallet.withdrawals.manage', 'wallet.settings.manage'
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
