<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

final class TagRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'tags';
    }

    public function all(): array
    {
        return $this->tenantFindMany('SELECT * FROM tags WHERE 1 = 1 ORDER BY name');
    }

    public function findByName(string $name): ?array
    {
        return $this->tenantFindOne('SELECT * FROM tags WHERE name = :name', ['name' => $name]);
    }

    public function findById(int $id): ?array
    {
        return $this->tenantFindOne('SELECT * FROM tags WHERE id = :id', ['id' => $id]);
    }

    public function create(string $name, ?string $color = null): int
    {
        return $this->tenantInsertGetId(['name' => $name, 'color' => $color]);
    }

    /**
     * findOrCreate isn't race-safe under true concurrency (two
     * simultaneous requests creating the same new tag name could both
     * miss the SELECT and both attempt an INSERT) — acceptable for
     * Phase 5's traffic profile; the UNIQUE(organization_id, name)
     * constraint means the loser fails loudly rather than
     * corrupting data, which is the important property here.
     */
    public function findOrCreate(string $name, ?string $color = null): array
    {
        $existing = $this->findByName($name);

        if ($existing !== null) {
            return $existing;
        }

        $id = $this->create($name, $color);

        return $this->findById($id);
    }

    // --- Polymorphic attachment (taggables) ------------------------------
    // Not tenant-scoped directly (no organization_id column on
    // taggables) — authorization is inherited from the tag itself,
    // which IS tenant-scoped above.

    public function attach(int $tagId, string $taggableType, int $taggableId): void
    {
        $this->query(
            'INSERT IGNORE INTO taggables (tag_id, taggable_type, taggable_id) VALUES (:tag_id, :type, :id)',
            ['tag_id' => $tagId, 'type' => $taggableType, 'id' => $taggableId]
        );
    }

    public function detach(int $tagId, string $taggableType, int $taggableId): void
    {
        $this->query(
            'DELETE FROM taggables WHERE tag_id = :tag_id AND taggable_type = :type AND taggable_id = :id',
            ['tag_id' => $tagId, 'type' => $taggableType, 'id' => $taggableId]
        );
    }

    /** @return array[] the tag rows attached to one record */
    public function forSubject(string $taggableType, int $taggableId): array
    {
        return $this->findMany(
            'SELECT tags.* FROM tags
             INNER JOIN taggables ON taggables.tag_id = tags.id
             WHERE taggables.taggable_type = :type AND taggables.taggable_id = :id
             ORDER BY tags.name',
            ['type' => $taggableType, 'id' => $taggableId]
        );
    }

    /**
     * The reverse of forSubject() — every subject id of one type that
     * has this tag attached (e.g. "which file library documents are
     * tagged 'invoice'"). $tagId itself is org-scoped (callers look
     * it up via findByName()/findById() above first, which are
     * tenant-scoped), so this raw taggables query is safe without its
     * own organization_id check.
     *
     * @return int[]
     */
    public function subjectIdsWithTag(string $taggableType, int $tagId): array
    {
        $rows = $this->findMany(
            'SELECT taggable_id FROM taggables WHERE taggable_type = :type AND tag_id = :tag_id',
            ['type' => $taggableType, 'tag_id' => $tagId]
        );

        return array_map(fn ($row) => (int) $row['taggable_id'], $rows);
    }
}
