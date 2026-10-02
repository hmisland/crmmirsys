<?php

declare(strict_types=1);

namespace Modules\Knowledge;

use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\TenantScopedRepository;

final class FileLibraryUploaderRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'file_library_uploaders';
    }

    public function isUploader(int $organizationId, int $userId): bool
    {
        $row = $this->findOne(
            'SELECT 1 AS present FROM file_library_uploaders WHERE organization_id = :org_id AND user_id = :user_id',
            ['org_id' => $organizationId, 'user_id' => $userId]
        );

        return $row !== null;
    }

    /**
     * A correlated subquery (for the user's name) has its OWN WHERE,
     * which appears earlier in the raw SQL text than the outer
     * WHERE — tenantFindMany()'s automatic first-WHERE-only scoping
     * would target the subquery's WHERE by mistake. Explicit
     * org-qualification, same fix used everywhere else this pattern
     * comes up.
     */
    public function forOrganization(): array
    {
        return $this->findMany(
            "SELECT file_library_uploaders.*,
                (SELECT CONCAT(first_name, ' ', COALESCE(last_name, '')) FROM users WHERE users.id = file_library_uploaders.user_id) AS user_name
             FROM file_library_uploaders
             WHERE file_library_uploaders.organization_id = :org_id",
            ['org_id' => OrganizationContext::id()]
        );
    }

    public function grant(int $organizationId, int $userId, int $grantedByUserId): void
    {
        $this->query(
            'INSERT IGNORE INTO file_library_uploaders (organization_id, user_id, granted_by_user_id) VALUES (:org_id, :user_id, :granted_by)',
            ['org_id' => $organizationId, 'user_id' => $userId, 'granted_by' => $grantedByUserId]
        );
    }

    public function revoke(int $organizationId, int $userId): void
    {
        $this->query(
            'DELETE FROM file_library_uploaders WHERE organization_id = :org_id AND user_id = :user_id',
            ['org_id' => $organizationId, 'user_id' => $userId]
        );
    }
}
