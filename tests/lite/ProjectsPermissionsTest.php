<?php

declare(strict_types=1);

/**
 * Phase 5 Step 3 — Projects permissions: module-registered keys (P1),
 * default grants from module migration 0003, and what each role holds.
 * The route × role matrix is in ProjectMatrixTest.
 * crm_local only, one transaction, rolled back.
 */

require_once __DIR__ . '/Support/LocalApp.php';
require_once __DIR__ . '/Support/ProjectsLite.php';

use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Permission;
use Modules\Projects\ProjectPermission;
use Tests\Lite\Support\ProjectsLite;

$boot = ProjectsLite::booter();
$can = fn ($a, array $user, string $key, ?array $record = null): bool => $a->container()->make(AuthorizationEngine::class)->can($user, $key, $record);
$superAdmin = ['id' => 900004, 'organization_id' => null, 'role_id' => null, 'is_super_admin' => 1, 'team_id' => null, 'department_id' => null];

return [
    'ProjectPermission: 6 unique keys, all under projects.' => function () {
        LiteAssert::same(6, count(array_unique(ProjectPermission::ALL)));
        foreach (ProjectPermission::ALL as $key) {
            LiteAssert::true(str_starts_with($key, 'projects.'), $key);
        }
    },

    'real app: Projects registers exactly its 6 keys at boot; Core keys untouched' => function () use ($boot) {
        $boot();
        LiteAssert::same(ProjectPermission::ALL, Permission::moduleKeys('projects'));
        foreach (ProjectPermission::ALL as $key) {
            LiteAssert::true(Permission::exists($key), $key);
        }
        $core = array_values(array_diff(Permission::all(), Permission::moduleKeys()));
        foreach ($core as $key) {
            LiteAssert::false(str_starts_with($key, 'projects.'), "Core list has no {$key}");
        }
    },

    'role editor (/org/roles) lists the project keys' => function () use ($boot) {
        $a = $boot();
        $a->as($a->admin);
        $r = $a->request('GET', '/org/roles');
        LiteAssert::same(200, $r['status']);
        foreach (ProjectPermission::ALL as $key) {
            LiteAssert::contains('data-key="' . $key . '"', $r['body'], "checkbox for {$key}");
        }
    },

    'default grants (migration 0003) are exactly as designed' => function () use ($boot) {
        $a = $boot();
        $rows = $a->pdo->query(
            "SELECT CONCAT(r.`key`, ':', rp.permission_key, ':', rp.scope) FROM role_permissions rp JOIN roles r ON r.id = rp.role_id
             WHERE rp.permission_key LIKE 'projects.%' AND r.is_system = 1 AND r.`key` IN ('org_admin', 'member') ORDER BY 1"
        )->fetchAll(\PDO::FETCH_COLUMN);
        $expected = array_merge(
            ['member:projects.create:organization', 'member:projects.edit:own', 'member:projects.manage_members:own', 'member:projects.view:organization'],
            array_map(fn ($k) => "org_admin:{$k}:organization", ProjectPermission::ALL)
        );
        sort($expected);
        LiteAssert::same($expected, $rows);
    },

    'org_admin holds every project permission' => function () use ($boot, $can) {
        $a = $boot();
        foreach (ProjectPermission::ALL as $key) {
            LiteAssert::true($can($a, $a->admin, $key), $key);
        }
    },

    'member: view/create; edit + manage_members only on projects they manage; no delete/settings' => function () use ($boot, $can) {
        $a = $boot();
        $m = $a->member;
        LiteAssert::true($can($a, $m, ProjectPermission::VIEW));
        LiteAssert::true($can($a, $m, ProjectPermission::CREATE));
        foreach ([ProjectPermission::EDIT, ProjectPermission::MANAGE_MEMBERS] as $key) {
            LiteAssert::true($can($a, $m, $key, ['owner_id' => $m['id']]), "{$key} own row");
            LiteAssert::false($can($a, $m, $key, ['owner_id' => (int) $a->admin['id']]), "{$key} someone else's row");
        }
        LiteAssert::false($can($a, $m, ProjectPermission::DELETE));
        LiteAssert::false($can($a, $m, ProjectPermission::MANAGE_SETTINGS));
    },

    'a user without a role holds no project permission; super admin holds all' => function () use ($boot, $can, $superAdmin) {
        $a = $boot();
        foreach (ProjectPermission::ALL as $key) {
            LiteAssert::false($can($a, $a->noRole, $key), "no role: {$key}");
            LiteAssert::true($can($a, $superAdmin, $key), "super admin: {$key}");
        }
    },

    'rollback' => function () use ($boot) {
        $boot()->rollBack();
        LiteAssert::true(true);
    },
];
