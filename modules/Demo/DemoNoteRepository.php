<?php

declare(strict_types=1);

namespace Modules\Demo;

use App\Infrastructure\Repositories\TenantScopedRepository;

/**
 * Proves a module's Repository is a normal TenantScopedRepository,
 * same as any Core one — tenant isolation isn't a Core-only
 * privilege, per Phase 0 §58/§64.
 */
final class DemoNoteRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'demo_notes';
    }

    public function all(): array
    {
        return $this->tenantFindMany('SELECT * FROM demo_notes WHERE 1 = 1 ORDER BY created_at DESC');
    }

    public function create(string $body): int
    {
        return $this->tenantInsertGetId(['body' => trim($body)]);
    }
}
