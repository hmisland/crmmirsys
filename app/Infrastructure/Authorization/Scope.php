<?php

declare(strict_types=1);

namespace App\Infrastructure\Authorization;

/**
 * Per Phase 0 §17. ALL and ORGANIZATION both grant access to anything
 * reachable through normal tenant-scoped queries (TenantScopedRepository
 * already restricts those to the caller's own organization — ALL vs
 * ORGANIZATION only differs for Super Admin's cross-organization
 * SuperAdminContext::run() escape hatch). OWN/TEAM/DEPARTMENT are for
 * record-level checks once modules with owned records (deals,
 * tickets, ...) exist from Phase 5 onward.
 */
enum Scope: string
{
    case OWN = 'own';
    case TEAM = 'team';
    case DEPARTMENT = 'department';
    case ORGANIZATION = 'organization';
    case ALL = 'all';
}
