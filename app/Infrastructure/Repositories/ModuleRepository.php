<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

/**
 * Not tenant-scoped: modules exist platform-wide, not per organization
 * (that's what organization_modules is for).
 */
final class ModuleRepository extends BaseRepository
{
    public function findByKey(string $key): ?array
    {
        return $this->findOne('SELECT * FROM modules WHERE `key` = :key', ['key' => $key]);
    }

    public function findById(int $id): ?array
    {
        return $this->findOne('SELECT * FROM modules WHERE id = :id', ['id' => $id]);
    }

    public function all(): array
    {
        return $this->findMany('SELECT * FROM modules ORDER BY is_core DESC, name');
    }

    public function create(array $data): int
    {
        return $this->insertGetId('modules', $data);
    }

    public function updateModule(int $id, array $fields): void
    {
        if ($fields === []) {
            return;
        }

        $this->update('modules', $fields, 'id = :id', ['id' => $id]);
    }
}
