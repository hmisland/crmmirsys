<?php

declare(strict_types=1);

namespace Tests\Lite\Support;

use Modules\Projects\Services\ProjectService;

/**
 * Shared helpers for the Projects suites (Phase 5 Step 3), on top of
 * LocalApp: boot once per suite (skip when crm_local or the Projects
 * tables are missing), install + entitle the module inside the suite's
 * transaction, and a few row factories.
 */
final class ProjectsLite
{
    /** @return \Closure(): LocalApp */
    public static function booter(bool $withContracts = false): \Closure
    {
        $h = new \stdClass();
        $h->app = null;
        $h->reason = '';

        return function () use ($h, $withContracts): LocalApp {
            if ($h->app !== null) {
                return $h->app;
            }
            if ($h->reason !== '') {
                throw new \LiteSkip($h->reason);
            }
            try {
                $a = LocalApp::boot();
                foreach (['projects', 'project_members', 'project_links', 'project_settings'] as $table) {
                    if ((int) $a->pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}'")->fetchColumn() !== 1) {
                        $a->rollBack();
                        throw new \RuntimeException("table {$table} missing (run the Projects module migrations)");
                    }
                }
            } catch (\Throwable $e) {
                $h->reason = 'local database unavailable: ' . $e->getMessage();
                throw new \LiteSkip($h->reason);
            }
            if ($a->member === null) {
                $a->rollBack();
                $h->reason = 'needs a second active user in the organization';
                throw new \LiteSkip($h->reason);
            }
            self::activate($a, 'projects', 'Projects');
            if ($withContracts) {
                $a->activateContracts();
            }
            $h->app = $a;

            return $a;
        };
    }

    /** Super-Admin-style install + entitlement of a module for the org (inside the transaction). */
    public static function activate(LocalApp $a, string $key, string $name): int
    {
        $a->pdo->prepare('DELETE FROM modules WHERE `key` = ?')->execute([$key]);
        $a->pdo->prepare("INSERT INTO modules (`key`, name, version, type, is_core, status, dependencies) VALUES (?, ?, '0.1.0', 'addon', 0, 'active', '[]')")->execute([$key, $name]);
        $id = (int) $a->pdo->lastInsertId();
        $a->pdo->prepare('INSERT INTO organization_modules (organization_id, module_id, enabled) VALUES (?, ?, 1)')->execute([$a->orgId, $id]);

        return $id;
    }

    public static function service(LocalApp $a): ProjectService
    {
        return $a->container()->make(ProjectService::class);
    }

    public static function contactId(LocalApp $a): int
    {
        return (int) $a->pdo->query("SELECT id FROM contacts WHERE organization_id = {$a->orgId} ORDER BY id LIMIT 1")->fetchColumn();
    }

    public static function companyId(LocalApp $a): int
    {
        return (int) $a->pdo->query("SELECT id FROM companies WHERE organization_id = {$a->orgId} ORDER BY id LIMIT 1")->fetchColumn();
    }

    /** A deal of the org (Sales installed and entitled in crm_local), or null. */
    public static function dealId(LocalApp $a): ?int
    {
        $exists = (int) $a->pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'deals'")->fetchColumn();
        $id = $exists === 1 ? $a->pdo->query("SELECT id FROM deals WHERE organization_id = {$a->orgId} ORDER BY id LIMIT 1")->fetchColumn() : false;

        return $id === false ? null : (int) $id;
    }

    /** A row of another organization (raw insert, inside the transaction). */
    public static function foreignProject(LocalApp $a): ?int
    {
        if ($a->otherOrgId === null) {
            return null;
        }
        $owner = $a->pdo->query("SELECT id FROM users WHERE organization_id = {$a->otherOrgId} ORDER BY id LIMIT 1")->fetchColumn();
        $owner = $owner === false ? (int) $a->admin['id'] : (int) $owner;
        $a->pdo->prepare("INSERT INTO projects (organization_id, code, name, status, owner_id, created_by_user_id) VALUES (?, ?, 'XORG project', 'active', ?, ?)")
            ->execute([$a->otherOrgId, 'XORG-' . bin2hex(random_bytes(3)), $owner, $owner]);

        return (int) $a->pdo->lastInsertId();
    }
}
