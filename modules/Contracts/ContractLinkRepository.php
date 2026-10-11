<?php

declare(strict_types=1);

namespace Modules\Contracts;

use App\Infrastructure\Repositories\TenantScopedRepository;

/** contract_links (module migration 0004) — tenant-scoped like every other table. */
final class ContractLinkRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'contract_links';
    }

    public function forContract(int $contractId): array
    {
        return $this->tenantFindMany(
            'SELECT * FROM contract_links WHERE contract_id = :contract_id ORDER BY created_at, id',
            ['contract_id' => $contractId]
        );
    }

    /** @return int[] contract ids linked to one record of another module */
    public function contractIdsLinkedTo(string $linkedType, int $linkedId): array
    {
        $rows = $this->tenantFindMany(
            'SELECT contract_id FROM contract_links WHERE linked_type = :type AND linked_id = :id ORDER BY contract_id DESC',
            ['type' => $linkedType, 'id' => $linkedId]
        );

        return array_map(fn (array $r) => (int) $r['contract_id'], $rows);
    }

    public function find(int $contractId, string $linkedType, int $linkedId): ?array
    {
        return $this->tenantFindOne(
            'SELECT * FROM contract_links WHERE contract_id = :contract_id AND linked_type = :type AND linked_id = :id',
            ['contract_id' => $contractId, 'type' => $linkedType, 'id' => $linkedId]
        );
    }

    public function create(int $contractId, string $linkedType, int $linkedId, string $label, ?int $userId): int
    {
        return $this->tenantInsertGetId([
            'contract_id' => $contractId,
            'linked_type' => $linkedType,
            'linked_id' => $linkedId,
            'linked_label' => mb_substr($label, 0, 255),
            'created_by_user_id' => $userId,
        ]);
    }

    public function deleteLink(int $id): void
    {
        $this->tenantDelete('id = :id', ['id' => $id]);
    }
}
