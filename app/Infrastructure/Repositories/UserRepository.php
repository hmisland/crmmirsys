<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

/**
 * Deliberately extends BaseRepository, NOT TenantScopedRepository.
 * Authentication happens BEFORE an OrganizationContext exists: you
 * look a user up by email to find out who they are and which
 * organization (if any) they belong to — you don't already know the
 * organization going in, so there's nothing to scope by yet.
 * TenantMiddleware (Phase 3) sets OrganizationContext FROM the
 * authenticated user's own organization_id, once org membership
 * exists — this repository is upstream of that, not scoped by it.
 */
final class UserRepository extends BaseRepository
{
    public function findByEmail(string $email): ?array
    {
        return $this->findOne('SELECT * FROM users WHERE email = :email', ['email' => $email]);
    }

    public function findById(int $id): ?array
    {
        return $this->findOne('SELECT * FROM users WHERE id = :id', ['id' => $id]);
    }

    public function create(array $data): int
    {
        return $this->insertGetId('users', $data);
    }

    public function updatePasswordHash(int $id, string $hash): void
    {
        $this->update('users', ['password_hash' => $hash], 'id = :id', ['id' => $id]);
    }

    public function markEmailVerified(int $id): void
    {
        $this->update('users', ['email_verified_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
    }

    public function updateLastLogin(int $id): void
    {
        $this->update('users', ['last_login_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
    }

    public function updateProfile(int $id, array $fields): void
    {
        if ($fields === []) {
            return;
        }

        $this->update('users', $fields, 'id = :id', ['id' => $id]);
    }

    /**
     * Phase 3: org-management methods. Still explicit-parameter (not
     * TenantScopedRepository) for the same reason as the class
     * docblock — callers (OrganizationService/UserManagementService)
     * pass the organization id they've already verified the caller
     * belongs to, rather than relying on an automatic filter.
     */
    public function findAllInOrganization(int $organizationId): array
    {
        return $this->findMany(
            'SELECT * FROM users WHERE organization_id = :org_id ORDER BY first_name, last_name',
            ['org_id' => $organizationId]
        );
    }

    public function countInOrganization(int $organizationId): int
    {
        $row = $this->findOne(
            'SELECT COUNT(*) AS c FROM users WHERE organization_id = :org_id',
            ['org_id' => $organizationId]
        );

        return (int) ($row['c'] ?? 0);
    }

    public function assignToOrganization(int $userId, int $organizationId, int $roleId): void
    {
        $this->update(
            'users',
            ['organization_id' => $organizationId, 'role_id' => $roleId],
            'id = :id',
            ['id' => $userId]
        );
    }

    public function updateRole(int $userId, ?int $roleId): void
    {
        $this->update('users', ['role_id' => $roleId], 'id = :id', ['id' => $userId]);
    }

    public function updateStatus(int $userId, string $status): void
    {
        $this->update('users', ['status' => $status], 'id = :id', ['id' => $userId]);
    }

    /**
     * Super Admin → unverified users: every user on the platform whose
     * email is not verified yet, newest first, with the organization
     * name. Platform-wide on purpose (Super Admin only, see
     * SuperAdminController) — no organization filter.
     */
    public function findUnverified(): array
    {
        return $this->findMany(
            'SELECT u.id, u.email, u.first_name, u.last_name, u.status, u.organization_id, u.created_at, o.name AS organization_name
             FROM users u LEFT JOIN organizations o ON o.id = u.organization_id
             WHERE u.email_verified_at IS NULL
             ORDER BY u.created_at DESC, u.id DESC'
        );
    }
}
