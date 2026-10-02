<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

final class CustomFieldDefinitionRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'custom_field_definitions';
    }

    public function forEntityType(string $entityType): array
    {
        return $this->tenantFindMany(
            'SELECT * FROM custom_field_definitions WHERE entity_type = :type ORDER BY label',
            ['type' => $entityType]
        );
    }

    public function findByKey(string $entityType, string $fieldKey): ?array
    {
        return $this->tenantFindOne(
            'SELECT * FROM custom_field_definitions WHERE entity_type = :type AND field_key = :key',
            ['type' => $entityType, 'key' => $fieldKey]
        );
    }

    public function findById(int $id): ?array
    {
        return $this->tenantFindOne('SELECT * FROM custom_field_definitions WHERE id = :id', ['id' => $id]);
    }

    public function create(array $data): int
    {
        return $this->tenantInsertGetId($data);
    }

    public function deleteDefinition(int $id): void
    {
        $this->tenantDelete('id = :id', ['id' => $id]);
    }
}
