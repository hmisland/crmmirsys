<?php

declare(strict_types=1);

/**
 * Phase 5 Step 3 — Project pages through the real router + middleware:
 * list/show/create/edit/delete, status actions, members, sidebar, the
 * Core notes/tags/attachments routes on a project, CSRF, module gate.
 * crm_local only, one transaction, rolled back.
 */

require_once __DIR__ . '/Support/LocalApp.php';
require_once __DIR__ . '/Support/ProjectsLite.php';

use Tests\Lite\Support\ProjectsLite;

$boot = ProjectsLite::booter();
$idFrom = fn (?string $location): int => (int) preg_replace('#^/projects/(\d+)$#', '$1', (string) $location);

return [
    'list renders; sidebar shows the Projects group; New project button for admins' => function () use ($boot) {
        $a = $boot();
        $a->as($a->admin);
        $r = $a->request('GET', '/projects');
        LiteAssert::same(200, $r['status']);
        LiteAssert::contains('href="/projects"', $r['body'], 'sidebar link');
        LiteAssert::contains('href="/projects/create"', $r['body'], 'new button');
    },

    'create through the route → redirect to the project; validation error → 422 with field errors' => function () use ($boot, $idFrom) {
        $a = $boot();
        $a->as($a->admin);
        $r = $a->request('POST', '/projects', [], ['name' => 'LiteTest page', 'start_date' => '2026-01-01', 'expected_end_date' => '2026-02-01', 'contact_id' => (string) ProjectsLite::contactId($a)]);
        LiteAssert::same(302, $r['status']);
        $id = $idFrom($r['location']);
        LiteAssert::true($id > 0, 'redirect ' . $r['location']);
        $bad = $a->request('POST', '/projects', [], ['name' => '', 'start_date' => '2026-02-30']);
        LiteAssert::same(422, $bad['status']);
        LiteAssert::contains('field-error', $bad['body']);
        LiteAssert::contains('crm-datepicker', $bad['body'], 'date inputs use the datepicker');
    },

    'show page: code, status badge, lifecycle buttons, members, record support (tags/notes/files/timeline)' => function () use ($boot, $idFrom) {
        $a = $boot();
        $a->as($a->admin);
        $id = $idFrom($a->request('POST', '/projects', [], ['name' => 'LiteTest show'])['location']);
        $r = $a->request('GET', "/projects/{$id}");
        LiteAssert::same(200, $r['status']);
        LiteAssert::contains('LiteTest show', $r['body']);
        LiteAssert::contains('PRJ-', $r['body']);
        LiteAssert::contains('name="action" value="plan"', $r['body']);
        LiteAssert::contains('name="action" value="start"', $r['body']);
        LiteAssert::notContains('name="action" value="reopen"', $r['body'], 'no reopen on a draft');
        LiteAssert::contains("/records/project/{$id}/notes", $r['body'], 'Core notes form');
        LiteAssert::contains("action=\"/projects/{$id}/members\"", $r['body'], 'add member form');
    },

    'edit form + update; status route walks the lifecycle; completed project has no edit form' => function () use ($boot, $idFrom) {
        $a = $boot();
        $a->as($a->admin);
        $id = $idFrom($a->request('POST', '/projects', [], ['name' => 'LiteTest edit'])['location']);
        LiteAssert::same(200, $a->request('GET', "/projects/{$id}/edit")['status']);
        LiteAssert::same(302, $a->request('POST', "/projects/{$id}", [], ['name' => 'LiteTest edit 2'])['status']);
        LiteAssert::same('LiteTest edit 2', $a->pdo->query("SELECT name FROM projects WHERE id = {$id}")->fetchColumn());
        foreach (['start' => 'active', 'hold' => 'on_hold', 'resume' => 'active', 'complete' => 'completed'] as $action => $status) {
            $a->request('POST', "/projects/{$id}/status", [], ['action' => $action, 'actual_end_date' => '2026-04-01']);
            LiteAssert::same($status, $a->pdo->query("SELECT status FROM projects WHERE id = {$id}")->fetchColumn(), $action);
        }
        LiteAssert::same('2026-04-01', $a->pdo->query("SELECT actual_end_date FROM projects WHERE id = {$id}")->fetchColumn());
        LiteAssert::same('forbidden', $a->outcome('GET', "/projects/{$id}/edit"), 'no edit form for a completed project');
        // An invalid transition is refused with a flash and no change.
        $a->request('POST', "/projects/{$id}/status", [], ['action' => 'hold']);
        LiteAssert::same('completed', $a->pdo->query("SELECT status FROM projects WHERE id = {$id}")->fetchColumn());
        $r = $a->request('GET', "/projects/{$id}");
        LiteAssert::contains('name="action" value="reopen"', $r['body'], 'reopen offered');
    },

    'members through the routes: add, list, remove' => function () use ($boot, $idFrom) {
        $a = $boot();
        $a->as($a->admin);
        $id = $idFrom($a->request('POST', '/projects', [], ['name' => 'LiteTest members page'])['location']);
        $a->request('POST', "/projects/{$id}/members", [], ['user_id' => (string) $a->member['id']]);
        $memberId = (int) $a->pdo->query("SELECT id FROM project_members WHERE project_id = {$id}")->fetchColumn();
        LiteAssert::true($memberId > 0, 'added');
        LiteAssert::contains("/projects/{$id}/members/{$memberId}/delete", $a->request('GET', "/projects/{$id}")['body']);
        $a->request('POST', "/projects/{$id}/members/{$memberId}/delete");
        LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM project_members WHERE project_id = {$id}")->fetchColumn());
    },

    'Core /records routes accept the project type (note + tag), redirect back to the project' => function () use ($boot, $idFrom) {
        $a = $boot();
        $a->as($a->admin);
        $id = $idFrom($a->request('POST', '/projects', [], ['name' => 'LiteTest records'])['location']);
        $r = $a->request('POST', "/records/project/{$id}/notes", [], ['body' => 'project note']);
        LiteAssert::same("/projects/{$id}", $r['location']);
        LiteAssert::same(1, (int) $a->pdo->query("SELECT COUNT(*) FROM notes WHERE notable_type = 'project' AND notable_id = {$id}")->fetchColumn());
        LiteAssert::contains('project note', $a->request('GET', "/projects/{$id}")['body']);
    },

    'delete through the route (draft) → gone; not offered for an active project' => function () use ($boot, $idFrom) {
        $a = $boot();
        $a->as($a->admin);
        $id = $idFrom($a->request('POST', '/projects', [], ['name' => 'LiteTest delete'])['location']);
        LiteAssert::same('/projects', $a->request('POST', "/projects/{$id}/delete")['location']);
        LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM projects WHERE id = {$id}")->fetchColumn());
        $id2 = $idFrom($a->request('POST', '/projects', [], ['name' => 'LiteTest delete 2'])['location']);
        $a->request('POST', "/projects/{$id2}/status", [], ['action' => 'start']);
        $a->request('POST', "/projects/{$id2}/delete");
        LiteAssert::same(1, (int) $a->pdo->query("SELECT COUNT(*) FROM projects WHERE id = {$id2}")->fetchColumn(), 'active project kept');
    },

    'filters on the list: search, status, mine, contact' => function () use ($boot, $idFrom) {
        $a = $boot();
        $a->as($a->admin);
        $contact = (string) ProjectsLite::contactId($a);
        $idFrom($a->request('POST', '/projects', [], ['name' => 'LiteTest omega filter', 'contact_id' => $contact])['location']);
        LiteAssert::contains('LiteTest omega filter', $a->request('GET', '/projects', ['q' => 'omega filter'])['body']);
        LiteAssert::notContains('LiteTest omega filter', $a->request('GET', '/projects', ['q' => 'omega filter', 'status' => 'active'])['body']);
        LiteAssert::contains('LiteTest omega filter', $a->request('GET', '/projects', ['q' => 'omega filter', 'mine' => '1'])['body']);
        LiteAssert::contains('LiteTest omega filter', $a->request('GET', '/projects', ['contact' => $contact])['body']);
    },

    'CSRF: every POST without a token is refused' => function () use ($boot, $idFrom) {
        $a = $boot();
        $a->as($a->admin);
        $id = $idFrom($a->request('POST', '/projects', [], ['name' => 'LiteTest csrf'])['location']);
        foreach (['/projects', "/projects/{$id}", "/projects/{$id}/delete", "/projects/{$id}/status", "/projects/{$id}/members", "/projects/{$id}/members/1/delete"] as $path) {
            LiteAssert::same('csrf', $a->outcome('POST', $path, [], ['_csrf' => 'wrong', 'name' => 'x', 'action' => 'start']), $path);
        }
    },

    'module off for the organization → every route 404, sidebar link gone; on again → back' => function () use ($boot, $idFrom) {
        $a = $boot();
        $a->as($a->admin);
        $id = $idFrom($a->request('POST', '/projects', [], ['name' => 'LiteTest gate'])['location']);
        $a->setOrgEntitled('projects', false);
        foreach (['/projects', '/projects/create', "/projects/{$id}", "/projects/{$id}/edit"] as $path) {
            LiteAssert::same('notfound', $a->outcome('GET', $path), $path);
        }
        LiteAssert::same('notfound', $a->outcome('POST', "/projects/{$id}/status", [], ['action' => 'start']));
        LiteAssert::notContains('href="/projects"', $a->request('GET', '/contacts')['body'], 'sidebar link hidden');
        LiteAssert::same(1, (int) $a->pdo->query("SELECT COUNT(*) FROM projects WHERE id = {$id}")->fetchColumn(), 'data kept');
        $a->setOrgEntitled('projects', true);
        LiteAssert::same(200, $a->request('GET', "/projects/{$id}")['status']);
    },

    'rollback leaves no test data behind' => function () use ($boot) {
        $a = $boot();
        $a->rollBack();
        LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM projects WHERE name LIKE 'LiteTest%'")->fetchColumn());
    },
];
