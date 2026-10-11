<?php

declare(strict_types=1);

namespace Modules\Finance;

use App\Infrastructure\Repositories\BaseRepository;
use App\Infrastructure\Repositories\OrganizationContext;

/**
 * Read-only candidate rows for the expense form's "Allocate to project" and
 * "Contract reference" selectors (Stage 5.1, option 2 of the audit).
 *
 * Core has no list API for another module's records, so this reads the
 * minimum columns of `projects` / `contracts`, always filtered to the
 * caller's organization. It is ONLY a candidate list: ExpenseService keeps
 * just the rows the user may use (same rule as RecordTypeRegistry::find), and
 * every submitted id is re-checked on the server through find(). The caller
 * must first make sure the owning module is available to the user (the table
 * may not exist otherwise). Nothing here writes.
 */
final class AllocationOptionsRepository extends BaseRepository
{
    public const LIMIT = 500;

    /** @return array<int, array<string, mixed>> newest first */
    public function projects(int $limit = self::LIMIT): array
    {
        return $this->findMany(
            'SELECT id, organization_id, code, name, status, owner_id, team_id, department_id
               FROM projects WHERE organization_id = :org_id ORDER BY id DESC LIMIT ' . max(1, min($limit, self::LIMIT)),
            ['org_id' => OrganizationContext::id()]
        );
    }

    /** @return array<int, array<string, mixed>> newest first */
    public function contracts(int $limit = self::LIMIT): array
    {
        return $this->findMany(
            'SELECT id, organization_id, contract_number, title, status, owner_id, team_id, department_id
               FROM contracts WHERE organization_id = :org_id ORDER BY id DESC LIMIT ' . max(1, min($limit, self::LIMIT)),
            ['org_id' => OrganizationContext::id()]
        );
    }
}
