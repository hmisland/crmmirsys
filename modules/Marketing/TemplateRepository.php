<?php

declare(strict_types=1);

namespace Modules\Marketing;

use App\Infrastructure\Repositories\TenantScopedRepository;

/** marketing_templates — tenant-scoped. */
final class TemplateRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'marketing_templates';
    }

    public function findById(int $id): ?array
    {
        return $this->tenantFindOne('SELECT * FROM marketing_templates WHERE id = :id', ['id' => $id]);
    }

    public function all(?string $channel = null): array
    {
        return $channel === null
            ? $this->tenantFindMany('SELECT * FROM marketing_templates WHERE 1 = 1 ORDER BY name')
            : $this->tenantFindMany('SELECT * FROM marketing_templates WHERE channel = :channel ORDER BY name', ['channel' => $channel]);
    }

    public function create(array $data): int
    {
        return $this->tenantInsertGetId($data);
    }

    public function updateTemplate(int $id, array $fields): void
    {
        $this->tenantUpdate($fields, 'id = :id', ['id' => $id]);
    }

    public function deleteTemplate(int $id): void
    {
        $this->tenantDelete('id = :id', ['id' => $id]);
    }
}
