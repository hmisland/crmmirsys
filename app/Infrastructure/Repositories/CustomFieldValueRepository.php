<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

final class CustomFieldValueRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'custom_field_values';
    }

    /** @return array<string, string> field_key => value */
    public function forEntity(string $entityType, int $entityId): array
    {
        $rows = $this->tenantFindMany(
            'SELECT field_key, value FROM custom_field_values WHERE entity_type = :type AND entity_id = :id',
            ['type' => $entityType, 'id' => $entityId]
        );

        $result = [];
        foreach ($rows as $row) {
            $result[$row['field_key']] = $row['value'];
        }

        return $result;
    }

    public function countForField(string $entityType, string $fieldKey): int
    {
        $row = $this->tenantFindOne(
            "SELECT COUNT(*) AS c FROM custom_field_values WHERE entity_type = :type AND field_key = :key AND value IS NOT NULL AND value <> ''",
            ['type' => $entityType, 'key' => $fieldKey]
        );

        return (int) ($row['c'] ?? 0);
    }

    public function setValue(string $entityType, int $entityId, string $fieldKey, ?string $value): void
    {
        $existing = $this->tenantFindOne(
            'SELECT id FROM custom_field_values WHERE entity_type = :type AND entity_id = :id AND field_key = :key',
            ['type' => $entityType, 'id' => $entityId, 'key' => $fieldKey]
        );

        if ($existing === null) {
            $this->tenantInsertGetId([
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'field_key' => $fieldKey,
                'value' => $value,
            ]);

            return;
        }

        $this->tenantUpdate(['value' => $value], 'id = :id', ['id' => $existing['id']]);
    }
}
