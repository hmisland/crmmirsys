<?php

declare(strict_types=1);

/**
 * Follow-ups on contracts and projects (docs/followups-contract-project-design.md).
 *
 * Feature: create from the contract/project page (GET prefill + POST,
 * both validated server-side through RecordTypeRegistry::find()), the
 * Follow-ups panel on those pages, show/edit/complete keep the link and
 * the related timeline.
 * Security: tampered related_type / related_id, cross-tenant, own scope,
 * module disabled, missing / non-viewable records, service allow-list.
 * Regression: contact/lead pickers, automated deal links, translations.
 *
 * crm_local only, one transaction, rolled back.
 */

require_once __DIR__ . '/Support/LocalApp.php';
require_once __DIR__ . '/Support/ProjectsLite.php';

use App\Core\Exceptions\ValidationException;
use Modules\Contracts\Services\ContractService;
use Modules\Followups\Services\FollowUpService;
use Tests\Lite\Support\ProjectsLite;

$booter = ProjectsLite::booter(true);
$f = new \stdClass();
$f->ready = false;

$boot = function () use ($booter, $f) {
    $a = $booter();
    if ($f->ready) {
        return $a;
    }
    $a->as($a->admin);
    // Follow-ups active + entitled for the org (inside the transaction).
    $id = $a->pdo->query("SELECT id FROM modules WHERE `key` = 'followups'")->fetchColumn();
    if ($id === false) {
        throw new LiteSkip('Follow-ups not installed in crm_local');
    }
    $a->pdo->prepare("UPDATE modules SET status = 'active' WHERE id = ?")->execute([(int) $id]);
    $a->pdo->prepare('DELETE FROM organization_modules WHERE organization_id = ? AND module_id = ?')->execute([$a->orgId, (int) $id]);
    $a->pdo->prepare('INSERT INTO organization_modules (organization_id, module_id, enabled) VALUES (?, ?, 1)')->execute([$a->orgId, (int) $id]);

    $f->contract = (int) $a->container()->make(ContractService::class)->create($a->admin, ['type' => 'task', 'priority' => 'normal', 'title' => 'LiteTest FU contract'])['id'];
    $f->project = (int) ProjectsLite::service($a)->create($a->admin, ['name' => 'LiteTest FU project'])['id'];
    $f->contact = ProjectsLite::contactId($a);
    $f->lead = (int) $a->pdo->query("SELECT id FROM leads WHERE organization_id = {$a->orgId} ORDER BY id LIMIT 1")->fetchColumn();
    $f->foreign = null;
    if ($a->otherOrgId !== null) {
        $owner = $a->pdo->query("SELECT id FROM users WHERE organization_id = {$a->otherOrgId} ORDER BY id LIMIT 1")->fetchColumn();
        $owner = $owner === false ? (int) $a->admin['id'] : (int) $owner;
        $a->pdo->prepare('INSERT INTO contracts (organization_id, title, owner_id, created_by_user_id) VALUES (?, ?, ?, ?)')->execute([$a->otherOrgId, 'LiteTest XORG FU contract', $owner, $owner]);
        $contract = (int) $a->pdo->lastInsertId();
        $project = ProjectsLite::foreignProject($a);
        $f->foreign = ['contract' => $contract, 'project' => $project, 'owner' => $owner];
    }
    $f->ready = true;

    return $a;
};
$svc = fn ($a): FollowUpService => $a->container()->make(FollowUpService::class);
$created = function ($a, array $r): int {
    LiteAssert::same(302, $r['status'], 'expected a redirect, got ' . $r['status']);

    return (int) preg_replace('#^/followups/(\d+)$#', '$1', (string) $r['location']);
};
$count = fn ($a, string $where): int => (int) $a->pdo->query("SELECT COUNT(*) FROM follow_ups WHERE {$where}")->fetchColumn();
/** Temporarily narrows a member-role grant (rolled back with the suite anyway). */
$withScope = function ($a, string $key, ?string $scope, callable $fn): void {
    $old = $a->pdo->query("SELECT scope FROM role_permissions WHERE role_id = {$a->memberRoleId} AND permission_key = " . $a->pdo->quote($key))->fetchColumn();
    if ($scope === null) {
        $a->pdo->prepare('DELETE FROM role_permissions WHERE role_id = ? AND permission_key = ?')->execute([$a->memberRoleId, $key]);
    } else {
        $a->pdo->prepare('INSERT INTO role_permissions (role_id, permission_key, scope) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE scope = VALUES(scope)')->execute([$a->memberRoleId, $key, $scope]);
    }
    try {
        $fn();
    } finally {
        $a->pdo->prepare('DELETE FROM role_permissions WHERE role_id = ? AND permission_key = ?')->execute([$a->memberRoleId, $key]);
        if ($old !== false) {
            $a->pdo->prepare('INSERT INTO role_permissions (role_id, permission_key, scope) VALUES (?, ?, ?)')->execute([$a->memberRoleId, $key, $old]);
        }
    }
};

$tests = [];

// ---- Feature ----------------------------------------------------------------
foreach (['contract' => 'LiteTest FU contract', 'project' => 'LiteTest FU project'] as $type => $title) {
    $tests["F1. {$type}: GET prefill fixes the link (read-only, hidden fields, no contact/lead pickers)"] = function () use ($boot, $f, $type, $title) {
        $a = $boot();
        $a->as($a->admin);
        $r = $a->request('GET', '/followups/create', ['related_type' => $type, 'related_id' => (string) $f->{$type}]);
        LiteAssert::same(200, $r['status']);
        LiteAssert::contains('name="related_type" value="' . $type . '"', $r['body']);
        LiteAssert::contains('name="related_id" value="' . $f->{$type} . '"', $r['body']);
        LiteAssert::contains($title, $r['body']);
        LiteAssert::contains('href="/' . $type . 's/' . $f->{$type} . '"', $r['body']);
        LiteAssert::notContains('name="related_contact_id"', $r['body']);
    };

    $tests["F2. {$type}: POST creates the linked follow-up; follow_up_created lands on the {$type}'s timeline"] = function () use ($boot, $f, $type, $created) {
        $a = $boot();
        $a->as($a->admin);
        $id = $created($a, $a->request('POST', '/followups', [], ['type' => 'task', 'priority' => 'normal', 'title' => "LiteTest FU on {$type}", 'related_type' => $type, 'related_id' => (string) $f->{$type}]));
        $row = $a->pdo->query("SELECT related_type, related_id FROM follow_ups WHERE id = {$id}")->fetch(\PDO::FETCH_ASSOC);
        LiteAssert::same(['related_type' => $type, 'related_id' => $f->{$type}], ['related_type' => $row['related_type'], 'related_id' => (int) $row['related_id']]);
        LiteAssert::same(1, (int) $a->pdo->query("SELECT COUNT(*) FROM timeline_events WHERE subject_type = '{$type}' AND subject_id = {$f->{$type}} AND type = 'follow_up_created' AND description LIKE '%LiteTest FU on {$type}%'")->fetchColumn());
    };

    $tests["F3. {$type} page shows the Follow-ups panel with its follow-ups and a New follow-up button"] = function () use ($boot, $f, $type, $created) {
        $a = $boot();
        $a->as($a->admin);
        $created($a, $a->request('POST', '/followups', [], ['type' => 'task', 'priority' => 'normal', 'title' => "LiteTest FU panel {$type}", 'related_type' => $type, 'related_id' => (string) $f->{$type}]));
        $body = $a->request('GET', '/' . $type . 's/' . $f->{$type})['body'];
        LiteAssert::contains('data-related-panel="followups.' . $type . '"', $body);
        LiteAssert::contains("LiteTest FU panel {$type}", $body);
        LiteAssert::contains(htmlspecialchars('/followups/create?related_type=' . $type . '&related_id=' . $f->{$type}), $body);
    };

    $tests["F4. {$type}: show links back; edit keeps the link (even if the request tries to change it); complete writes on the {$type} timeline"] = function () use ($boot, $f, $type, $created) {
        $a = $boot();
        $a->as($a->admin);
        $id = $created($a, $a->request('POST', '/followups', [], ['type' => 'task', 'priority' => 'normal', 'title' => "LiteTest FU edit {$type}", 'related_type' => $type, 'related_id' => (string) $f->{$type}]));
        LiteAssert::contains('href="/' . $type . 's/' . $f->{$type} . '"', $a->request('GET', "/followups/{$id}")['body']);
        $edit = $a->request('GET', "/followups/{$id}/edit")['body'];
        LiteAssert::notContains('name="related_contact_id"', $edit, 'link read-only on edit');
        $a->request('POST', "/followups/{$id}", [], ['type' => 'task', 'priority' => 'normal', 'title' => "LiteTest FU edit {$type} 2", 'related_type' => 'lead', 'related_id' => '1']);
        $row = $a->pdo->query("SELECT title, related_type, related_id FROM follow_ups WHERE id = {$id}")->fetch(\PDO::FETCH_ASSOC);
        LiteAssert::same(["LiteTest FU edit {$type} 2", $type, $f->{$type}], [$row['title'], $row['related_type'], (int) $row['related_id']]);
        $a->request('POST', "/followups/{$id}/complete");
        LiteAssert::same(1, (int) $a->pdo->query("SELECT COUNT(*) FROM timeline_events WHERE subject_type = '{$type}' AND subject_id = {$f->{$type}} AND type = 'follow_up_completed'")->fetchColumn() >= 1 ? 1 : 0);
    };
}

// ---- Security: GET prefill ---------------------------------------------------
$tests['S1. GET prefill → 404 for a missing id, another organization\'s contract/project, and id 0'] = function () use ($boot, $f) {
    $a = $boot();
    $a->as($a->admin);
    $cases = [['contract', '999999999'], ['project', '999999999'], ['contract', '0'], ['project', 'abc']];
    if ($f->foreign !== null) {
        $cases[] = ['contract', (string) $f->foreign['contract']];
        $cases[] = ['project', (string) $f->foreign['project']];
    }
    $wrong = [];
    foreach ($cases as [$type, $id]) {
        $got = $a->outcome('GET', '/followups/create', ['related_type' => $type, 'related_id' => $id]);
        if ($got !== 'notfound') {
            $wrong[] = "{$type}#{$id}: {$got}";
        }
    }
    LiteAssert::same([], $wrong, implode(' | ', $wrong));
};

$tests['S2. record scope: a member whose contracts.view / projects.view is OWN gets 404 (GET) / 422 (POST) for someone else\'s record'] = function () use ($boot, $f, $withScope) {
    $a = $boot();
    foreach (['contract' => 'contracts.view', 'project' => 'projects.view'] as $type => $key) {
        $withScope($a, $key, 'own', function () use ($a, $f, $type) {
            $a->as($a->member);
            LiteAssert::same('notfound', $a->outcome('GET', '/followups/create', ['related_type' => $type, 'related_id' => (string) $f->{$type}]), "{$type} prefill");
            LiteAssert::same('ok', $a->outcome('POST', '/followups', [], ['type' => 'task', 'priority' => 'normal', 'title' => 'LiteTest FU scope', 'related_type' => $type, 'related_id' => (string) $f->{$type}]));
            $a->as($a->admin);
        });
    }
    LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM follow_ups WHERE title = 'LiteTest FU scope'")->fetchColumn(), 'out-of-scope POST created nothing (422)');
};

$tests['S3. no view permission on the record type at all → 404 on prefill, 422 on submit'] = function () use ($boot, $f, $withScope) {
    $a = $boot();
    $withScope($a, 'contracts.view', null, function () use ($a, $f) {
        $a->as($a->member);
        LiteAssert::same('notfound', $a->outcome('GET', '/followups/create', ['related_type' => 'contract', 'related_id' => (string) $f->contract]));
        $r = $a->request('POST', '/followups', [], ['type' => 'task', 'priority' => 'normal', 'title' => 'LiteTest FU noperm', 'related_type' => 'contract', 'related_id' => (string) $f->contract]);
        LiteAssert::same(422, $r['status']);
        $a->as($a->admin);
    });
    LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM follow_ups WHERE title = 'LiteTest FU noperm'")->fetchColumn());
};

// ---- Security: tampered POST ------------------------------------------------
$tests['S4. tampered POST → 422 and nothing created (foreign id, missing id, bad type, deal/contact/lead by hand, two links)'] = function () use ($boot, $f, $count) {
    $a = $boot();
    $a->as($a->admin);
    $cases = [
        ['related_type' => 'contract', 'related_id' => '999999999'],
        ['related_type' => 'project', 'related_id' => '999999999'],
        ['related_type' => 'contract', 'related_id' => ''],
        ['related_type' => 'widget', 'related_id' => '1'],
        ['related_type' => 'deal', 'related_id' => '1'],
        ['related_type' => 'contact', 'related_id' => (string) $f->contact],
        ['related_type' => 'lead', 'related_id' => '1'],
        ['related_type' => 'follow_up', 'related_id' => '1'],
        ['related_type' => 'contract', 'related_id' => (string) $f->contract, 'related_contact_id' => (string) $f->contact],
    ];
    if ($f->foreign !== null) {
        $cases[] = ['related_type' => 'contract', 'related_id' => (string) $f->foreign['contract']];
        $cases[] = ['related_type' => 'project', 'related_id' => (string) $f->foreign['project']];
    }
    $before = $count($a, "title = 'LiteTest FU tamper'");
    $wrong = [];
    foreach ($cases as $case) {
        $r = $a->request('POST', '/followups', [], ['type' => 'task', 'priority' => 'normal', 'title' => 'LiteTest FU tamper'] + $case);
        if ($r['status'] !== 422) {
            $wrong[] = json_encode($case) . " → {$r['status']}";
        }
    }
    LiteAssert::same([], $wrong, implode(' | ', $wrong));
    LiteAssert::same($before, $count($a, "title = 'LiteTest FU tamper'"), 'nothing created');
    if ($f->foreign !== null) {
        LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM timeline_events WHERE subject_type = 'contract' AND subject_id = {$f->foreign['contract']}")->fetchColumn(), 'no timeline on the foreign contract');
    }
};

$tests['S5. service allow-list: only contact, lead, deal, contract, project; anything else rejected'] = function () use ($boot, $svc) {
    $a = $boot();
    $a->as($a->admin);
    foreach (['widget', 'follow_up', 'task', 'ticket', 'CONTRACT', 'contract ', 'client_record'] as $bad) {
        LiteAssert::throws(ValidationException::class, fn () => $svc($a)->create($a->admin, ['type' => 'task', 'priority' => 'normal', 'title' => 'LiteTest FU svc', 'related_type' => $bad, 'related_id' => '1']), $bad);
    }
    foreach (FollowUpService::RELATED_TYPES as $ok) {
        $row = $svc($a)->create($a->admin, ['type' => 'task', 'priority' => 'normal', 'title' => 'LiteTest FU svc ok', 'related_type' => $ok, 'related_id' => '1']);
        LiteAssert::same($ok, $row['related_type']);
    }
    LiteAssert::same(null, $svc($a)->create($a->admin, ['type' => 'task', 'priority' => 'normal', 'title' => 'LiteTest FU svc none'])['related_type'], 'no link is still fine');
    LiteAssert::same(['contact', 'lead', 'deal', 'contract', 'project'], FollowUpService::RELATED_TYPES);
};

// ---- Security: panel rows ----------------------------------------------------
$tests['S6. panel rows follow followups.view scope: OWN member sees only their own; another organization\'s rows never appear'] = function () use ($boot, $f, $svc, $withScope) {
    $a = $boot();
    $a->as($a->admin);
    $svc($a)->create($a->admin, ['type' => 'task', 'priority' => 'normal', 'title' => 'LiteTest FU admin-owned', 'related_type' => 'contract', 'related_id' => (string) $f->contract]);
    $svc($a)->create($a->member, ['type' => 'task', 'priority' => 'normal', 'title' => 'LiteTest FU member-owned', 'related_type' => 'contract', 'related_id' => (string) $f->contract]);
    if ($f->foreign !== null) {
        // A row of the other organization pointing at MY contract's id.
        $a->pdo->prepare("INSERT INTO follow_ups (organization_id, title, owner_id, created_by_user_id, related_type, related_id) VALUES (?, 'LiteTest FU XORG row', ?, ?, 'contract', ?)")
            ->execute([$a->otherOrgId, $f->foreign['owner'], $f->foreign['owner'], $f->contract]);
    }
    // Only the panel itself (the contract timeline also mentions follow-ups, by design).
    $page = function () use ($a, $f): string {
        $body = $a->request('GET', "/contracts/{$f->contract}")['body'];
        $start = strpos($body, 'data-related-panel="followups.contract"');
        LiteAssert::true($start !== false, 'panel rendered');

        return substr($body, $start, strpos($body, '</section>', $start) - $start);
    };
    $body = $page();
    LiteAssert::contains('LiteTest FU admin-owned', $body);
    LiteAssert::contains('LiteTest FU member-owned', $body);
    LiteAssert::notContains('LiteTest FU XORG row', $body, 'cross-tenant');

    $withScope($a, 'followups.view', 'own', function () use ($a, $page) {
        $a->as($a->member);
        $body = $page();
        LiteAssert::contains('LiteTest FU member-owned', $body);
        LiteAssert::notContains('LiteTest FU admin-owned', $body, 'own scope');
        LiteAssert::notContains('LiteTest FU XORG row', $body);
        $a->as($a->admin);
    });
};

$tests['S7. panel "New follow-up" button only with followups.create'] = function () use ($boot, $f, $withScope) {
    $a = $boot();
    $withScope($a, 'followups.create', null, function () use ($a, $f) {
        $a->as($a->member);
        $body = $a->request('GET', "/projects/{$f->project}")['body'];
        LiteAssert::contains('data-related-panel="followups.project"', $body);
        LiteAssert::notContains('/followups/create?related_type=project', $body);
        $a->as($a->admin);
    });
};

$tests['S8. project member with OWN projects.view: sees the project page (membership) but no "New follow-up", and the server refuses it too'] = function () use ($boot, $f, $withScope) {
    $a = $boot();
    $a->as($a->admin);
    ProjectsLite::service($a)->addMember($a->admin, $f->project, (int) $a->member['id']);
    $withScope($a, 'projects.view', 'own', function () use ($a, $f) {
        $a->as($a->member);
        $body = $a->request('GET', "/projects/{$f->project}")['body'];
        LiteAssert::contains('data-related-panel="followups.project"', $body, 'panel visible to the member');
        LiteAssert::notContains('/followups/create?related_type=project', $body, 'button hidden: find() refuses');
        LiteAssert::same('notfound', $a->outcome('GET', '/followups/create', ['related_type' => 'project', 'related_id' => (string) $f->project]));
        LiteAssert::same(422, $a->request('POST', '/followups', [], ['type' => 'task', 'priority' => 'normal', 'title' => 'LiteTest FU member edge', 'related_type' => 'project', 'related_id' => (string) $f->project])['status']);
        $a->as($a->admin);
    });
};

// ---- Module states ----------------------------------------------------------
$tests['M1. Follow-ups off → no panel on contract/project pages; on again → back'] = function () use ($boot, $f) {
    $a = $boot();
    $a->as($a->admin);
    $a->setOrgEntitled('followups', false);
    try {
        LiteAssert::notContains('data-related-panel="followups.contract"', $a->request('GET', "/contracts/{$f->contract}")['body']);
        LiteAssert::notContains('data-related-panel="followups.project"', $a->request('GET', "/projects/{$f->project}")['body']);
        LiteAssert::same('notfound', $a->outcome('GET', '/followups/create', ['related_type' => 'contract', 'related_id' => (string) $f->contract]));
    } finally {
        $a->setOrgEntitled('followups', true);
    }
    LiteAssert::contains('data-related-panel="followups.contract"', $a->request('GET', "/contracts/{$f->contract}")['body']);
};

foreach (['contracts' => 'contract', 'projects' => 'project'] as $module => $type) {
    $tests["M2. {$module} off → {$type} refused (GET 404, POST 422); existing follow-up shows a plain '#id' label; /followups still works"] = function () use ($boot, $f, $module, $type, $created) {
        $a = $boot();
        $a->as($a->admin);
        $id = $created($a, $a->request('POST', '/followups', [], ['type' => 'task', 'priority' => 'normal', 'title' => "LiteTest FU off {$type}", 'related_type' => $type, 'related_id' => (string) $f->{$type}]));
        $a->setOrgEntitled($module, false);
        try {
            LiteAssert::same('notfound', $a->outcome('GET', '/followups/create', ['related_type' => $type, 'related_id' => (string) $f->{$type}]));
            LiteAssert::same(422, $a->request('POST', '/followups', [], ['type' => 'task', 'priority' => 'normal', 'title' => "LiteTest FU off2 {$type}", 'related_type' => $type, 'related_id' => (string) $f->{$type}])['status']);
            $show = $a->request('GET', "/followups/{$id}");
            LiteAssert::same(200, $show['status']);
            LiteAssert::contains('#' . $f->{$type}, $show['body']);
            LiteAssert::notContains('href="/' . $type . 's/' . $f->{$type} . '"', $show['body']);
            LiteAssert::same(200, $a->request('GET', '/followups')['status']);
            LiteAssert::same(200, $a->request('GET', "/followups/{$id}/edit")['status']);
        } finally {
            $a->setOrgEntitled($module, true);
        }
    };
}

// ---- Regression -------------------------------------------------------------
$tests['R1. contact and lead pickers work as before (create, preselect, edit, one-link rule)'] = function () use ($boot, $f, $created) {
    $a = $boot();
    $a->as($a->admin);
    $pre = $a->request('GET', '/followups/create', ['related_type' => 'contact', 'related_id' => (string) $f->contact])['body'];
    LiteAssert::contains('name="related_contact_id"', $pre);
    LiteAssert::notContains('name="related_type"', $pre, 'no fixed link for a contact');
    $id = $created($a, $a->request('POST', '/followups', [], ['type' => 'task', 'priority' => 'normal', 'title' => 'LiteTest FU contact', 'related_contact_id' => (string) $f->contact, 'related_lead_id' => '']));
    LiteAssert::same('contact', $a->pdo->query("SELECT related_type FROM follow_ups WHERE id = {$id}")->fetchColumn());
    if ($f->lead > 0) {
        $a->request('POST', "/followups/{$id}", [], ['type' => 'task', 'priority' => 'normal', 'title' => 'LiteTest FU contact→lead', 'related_contact_id' => '', 'related_lead_id' => (string) $f->lead]);
        LiteAssert::same('lead', $a->pdo->query("SELECT related_type FROM follow_ups WHERE id = {$id}")->fetchColumn());
        LiteAssert::same(422, $a->request('POST', '/followups', [], ['type' => 'task', 'priority' => 'normal', 'title' => 'LiteTest FU both', 'related_contact_id' => (string) $f->contact, 'related_lead_id' => (string) $f->lead])['status']);
    }
    $none = $created($a, $a->request('POST', '/followups', [], ['type' => 'task', 'priority' => 'normal', 'title' => 'LiteTest FU unlinked']));
    LiteAssert::same(null, $a->pdo->query("SELECT related_type FROM follow_ups WHERE id = {$none}")->fetchColumn());
};

$tests['R2. an automated deal follow-up keeps its deal link on edit'] = function () use ($boot, $svc) {
    $a = $boot();
    $a->as($a->admin);
    $id = $svc($a)->createAutomated(['organization_id' => $a->orgId, 'title' => 'LiteTest FU deal auto', 'owner_id' => (int) $a->admin['id'], 'related_type' => 'deal', 'related_id' => 1]);
    $edit = $a->request('GET', "/followups/{$id}/edit")['body'];
    LiteAssert::notContains('name="related_contact_id"', $edit);
    $a->request('POST', "/followups/{$id}", [], ['type' => 'task', 'priority' => 'normal', 'title' => 'LiteTest FU deal auto 2']);
    LiteAssert::same(['deal', 1], array_values(array_map(fn ($v) => is_numeric($v) ? (int) $v : $v, $a->pdo->query("SELECT related_type, related_id FROM follow_ups WHERE id = {$id}")->fetch(\PDO::FETCH_ASSOC))));
};

$tests['R3. new keys exist in all five languages; panel + fixed form render in fa/ar/en/tr/fr without raw keys'] = function () use ($boot, $f) {
    $a = $boot();
    $root = dirname(__DIR__, 2);
    foreach (['en', 'fa', 'ar', 'tr', 'fr'] as $lang) {
        $strings = require "{$root}/lang/{$lang}.php";
        foreach (['followups.related_type.contract', 'followups.related_type.project', 'followups.panel_empty'] as $key) {
            LiteAssert::true(isset($strings[$key]) && trim((string) $strings[$key]) !== '', "{$lang}:{$key}");
        }
    }
    $a->as($a->admin);
    $problems = [];
    foreach (['en', 'fa', 'ar', 'tr', 'fr'] as $lang) {
        $a->locale($lang);
        foreach ([["/contracts/{$f->contract}", []], ["/projects/{$f->project}", []], ['/followups/create', ['related_type' => 'project', 'related_id' => (string) $f->project]]] as [$path, $query]) {
            $r = $a->request('GET', $path, $query);
            $text = html_entity_decode(strip_tags((string) preg_replace('#<(script|style)\b.*?</\1>#s', '', $r['body'])));
            preg_match_all('/\bfollowups\.[a-z_]+(?:\.[a-z_]+)*\b/', $text, $m);
            if ($r['status'] !== 200 || $m[0] !== []) {
                $problems[] = "{$lang} {$path}: {$r['status']} " . implode(',', array_unique($m[0]));
            }
        }
    }
    $a->locale('en');
    LiteAssert::same([], $problems, implode(' | ', $problems));
};

$tests['rollback leaves no test data behind'] = function () use ($boot) {
    $a = $boot();
    $a->rollBack();
    LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM follow_ups WHERE title LIKE 'LiteTest%'")->fetchColumn());
};

return $tests;
