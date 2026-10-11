<?php

declare(strict_types=1);

/**
 * Phase 5 Step 3 — Projects skeleton: architecture + the `project`
 * record type.
 *
 * Part 1 (no DB): architecture — Projects references no other module
 *   (code and schema), the manifest has no dependencies, and the one
 *   allowed cross-module mention is the deal event's class-name string.
 * Part 2 (crm_local, one transaction, rolled back): the real module
 *   registers `project` with a finder, and the chain installed → active →
 *   org entitled → user override → permission → record scope → tenant holds.
 */

require_once __DIR__ . '/Support/LocalApp.php';
require_once __DIR__ . '/Support/ProjectsLite.php';

use App\Infrastructure\Modules\ModuleGate;
use App\Infrastructure\Modules\ModuleRegistry;
use App\Infrastructure\Records\RecordTypeRegistry;
use Modules\Projects\ProjectsModule;
use Tests\Lite\Support\ProjectsLite;

$boot = ProjectsLite::booter();
$types = fn ($a): RecordTypeRegistry => $a->container()->make(RecordTypeRegistry::class);

/** A raw project row of this org (no service yet in Part 2). */
$insert = function ($a, int $ownerId, string $name) {
    $a->pdo->prepare("INSERT INTO projects (organization_id, code, name, status, owner_id, created_by_user_id) VALUES (?, ?, ?, 'draft', ?, ?)")
        ->execute([$a->orgId, 'LT-' . bin2hex(random_bytes(3)), $name, $ownerId, $ownerId]);

    return (int) $a->pdo->lastInsertId();
};

return [
    // ---- Part 1: architecture ------------------------------------------
    'Projects module references no other module (code and schema)' => function () {
        $dir = dirname(__DIR__, 2) . '/modules/Projects';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        // top-level Modules\X other than Projects (not Core's App\Infrastructure\Modules\...)
        $forbiddenNamespaces = '/(?<!\w)(?<!\w\\\\)Modules\\\\{1,2}(?!Projects\b)[A-Z]/';
        $forbiddenTables = '/\b(deals|pipelines|pipeline_stages|tasks|invoices|proformas|payments|products|contracts|contract_[a-z_]+|follow_ups|tickets|appointments|wallets?)\b/';

        // The ONLY allowed mention of another module (decision G2): the deal
        // event's class name as a string literal, in ProjectsModule.php.
        $allowed = "'Modules\\\\Sales\\\\Events\\\\DealStageChanged'";
        $allowedSeen = 0;

        foreach ($files as $file) {
            $source = (string) file_get_contents($file->getPathname());
            if ($file->getFilename() === 'ProjectsModule.php') {
                $allowedSeen = substr_count($source, $allowed);
                $source = str_replace($allowed, "''", $source);
            }
            $code = str_ends_with($file->getFilename(), '.php')
                ? implode('', array_map(
                    fn ($t) => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t,
                    token_get_all($source)
                ))
                : $source;
            LiteAssert::same(0, preg_match($forbiddenNamespaces, $code), 'other module namespace in ' . $file->getFilename());
            LiteAssert::same(0, preg_match($forbiddenTables, $code), 'other module table in ' . $file->getFilename());
        }

        LiteAssert::same(1, $allowedSeen, 'the event-name string appears exactly once, in ProjectsModule.php');

        $manifest = json_decode((string) file_get_contents($dir . '/module.json'), true);
        LiteAssert::same('projects', $manifest['key']);
        LiteAssert::same('addon', $manifest['type']);
        LiteAssert::same([], $manifest['dependencies']);
    },

    'every Projects migration has up() and down()' => function () {
        foreach (glob(dirname(__DIR__, 2) . '/modules/Projects/Migrations/*.php') as $file) {
            $migration = require $file;
            LiteAssert::true($migration instanceof \App\Infrastructure\Database\MigrationInterface, basename($file));
            LiteAssert::true(method_exists($migration, 'down'), basename($file) . ' down()');
        }
    },

    'Contracts and Sales do not know Projects (no Projects reference in their code)' => function () {
        foreach (['Contracts', 'Sales'] as $module) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/modules/' . $module, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (!str_ends_with($file->getFilename(), '.php')) {
                    continue;
                }
                LiteAssert::same(0, preg_match('/Modules\\\\{1,2}Projects\b/', (string) file_get_contents($file->getPathname())), "{$module}/{$file->getFilename()}");
            }
        }
    },

    // ---- Part 2: real module, local DB ---------------------------------
    'real app discovers Projects and registers the `project` type with a finder' => function () use ($boot, $types) {
        $a = $boot();
        LiteAssert::true(in_array('projects', array_map(fn ($m) => $m->key, ModuleRegistry::instance()->all()), true));
        $type = $types($a)->get('project');
        LiteAssert::true($type !== null, 'registered');
        LiteAssert::same(ProjectsModule::KEY, $type->module);
        LiteAssert::same('projects.view', $type->viewPermission);
        LiteAssert::same('/projects/7', $type->showUrl(7));
        LiteAssert::true($type->finder !== null, 'finder');
        LiteAssert::true($type->recordSupport, 'notes/tags/files supported');
    },

    'find(): own org row for an org admin; another organization\'s row is never returned' => function () use ($boot, $types, $insert) {
        $a = $boot();
        $a->as($a->admin);
        $id = $insert($a, (int) $a->admin['id'], 'LiteTest RT');
        LiteAssert::same('LiteTest RT', $types($a)->find('project', $id, $a->admin)['name'] ?? null);

        $foreign = ProjectsLite::foreignProject($a);
        if ($foreign !== null) {
            LiteAssert::same(null, $types($a)->find('project', $foreign, $a->admin), 'other org');
        }
    },

    'find(): record scope applies (member with own scope cannot see a project managed by someone else)' => function () use ($boot, $types, $insert) {
        $a = $boot();
        $id = $insert($a, (int) $a->admin['id'], 'LiteTest RT scope');
        // member role has projects.view at organization scope → visible
        LiteAssert::true($types($a)->find('project', $id, $a->member) !== null, 'organization scope');
        $a->pdo->prepare("UPDATE role_permissions SET scope = 'own' WHERE role_id = ? AND permission_key = 'projects.view'")->execute([$a->memberRoleId]);
        try {
            LiteAssert::same(null, $types($a)->find('project', $id, $a->member), 'own scope, not the manager');
            $mine = $insert($a, (int) $a->member['id'], 'LiteTest RT mine');
            LiteAssert::true($types($a)->find('project', $mine, $a->member) !== null, 'own scope, manager');
        } finally {
            $a->pdo->prepare("UPDATE role_permissions SET scope = 'organization' WHERE role_id = ? AND permission_key = 'projects.view'")->execute([$a->memberRoleId]);
        }
    },

    'module chain: global off / org not entitled / user restricted → type unavailable; re-enabled → back' => function () use ($boot, $types, $insert) {
        $a = $boot();
        $id = $insert($a, (int) $a->admin['id'], 'LiteTest RT chain');
        $check = function (bool $expected, string $label) use ($a, $types, $id) {
            $a->container()->make(ModuleGate::class)->reset();
            LiteAssert::same($expected, $types($a)->canView('project', $a->member), $label . ' canView');
            LiteAssert::same($expected, $types($a)->find('project', $id, $a->member) !== null, $label . ' find');
        };
        $check(true, 'enabled');
        $a->setModuleStatus('projects', 'disabled');
        $check(false, 'global off');
        $a->setModuleStatus('projects', 'active');
        $a->setOrgEntitled('projects', false);
        $check(false, 'org off');
        $a->setOrgEntitled('projects', true);
        $a->setUserOverride((int) $a->member['id'], 'projects', false);
        $check(false, 'user restricted');
        $a->setUserOverride((int) $a->member['id'], 'projects', null);
        $check(true, 're-enabled');
        LiteAssert::same(1, (int) $a->pdo->query("SELECT COUNT(*) FROM projects WHERE id = {$id}")->fetchColumn(), 'data kept');
    },

    'a user without a role cannot view the type' => function () use ($boot, $types) {
        $a = $boot();
        LiteAssert::false($types($a)->canView('project', $a->noRole));
    },

    'rollback leaves no test data behind' => function () use ($boot) {
        $a = $boot();
        $a->rollBack();
        LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM projects WHERE name LIKE 'LiteTest%' OR code LIKE 'XORG-%'")->fetchColumn());
    },
];
