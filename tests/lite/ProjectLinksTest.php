<?php

declare(strict_types=1);

/**
 * Phase 5 Step 3 — Project ↔ Deal / Contract links and the Projects
 * panels on contact, company, deal and contract pages. Projects reads
 * deals and contracts only through RecordTypeRegistry; Sales and
 * Contracts are not changed. crm_local only, one transaction, rolled back.
 */

require_once __DIR__ . '/Support/LocalApp.php';
require_once __DIR__ . '/Support/ProjectsLite.php';

use App\Core\Exceptions\ValidationException;
use Modules\Contracts\Services\ContractService;
use Tests\Lite\Support\ProjectsLite;

$boot = ProjectsLite::booter(true);
$svc = fn ($a) => ProjectsLite::service($a);
$contract = function ($a, string $title = 'LiteTest PL contract') {
    return $a->container()->make(ContractService::class)->create($a->admin, [
        'title' => $title, 'contact_id' => (string) ProjectsLite::contactId($a), 'start_date' => '2026-02-01', 'end_date' => '2026-11-30',
    ]);
};
$deal = function ($a): int {
    $id = ProjectsLite::dealId($a);
    if ($id === null) {
        throw new LiteSkip('needs a deal in crm_local');
    }

    return $id;
};
$refused = function (callable $fn, string $field = 'link'): void {
    try {
        $fn();
    } catch (ValidationException $e) {
        LiteAssert::same(['projects.error.link_target_invalid'], $e->errors[$field] ?? null, json_encode($e->errors));

        return;
    }
    throw new LiteAssertionFailed('link was accepted');
};

return [
    'link a deal and a contract: idempotent, shown with live label + owner module URL, timeline entries' => function () use ($boot, $svc, $deal, $contract) {
        $a = $boot();
        $a->as($a->admin);
        $dealId = $deal($a);
        $c = $contract($a);
        $p = $svc($a)->create($a->admin, ['name' => 'LiteTest PL link']);
        $id = (int) $p['id'];
        $first = $svc($a)->link($a->admin, $id, 'deal', $dealId);
        LiteAssert::same($first['id'], $svc($a)->link($a->admin, $id, 'deal', $dealId)['id'], 'idempotent');
        $svc($a)->link($a->admin, $id, 'contract', (int) $c['id']);
        $links = $svc($a)->links($a->admin, $id);
        LiteAssert::same(['deal', 'contract'], array_column($links, 'type'));
        LiteAssert::same("/deals/{$dealId}", $links[0]['url']);
        LiteAssert::same("/contracts/{$c['id']}", $links[1]['url']);
        LiteAssert::same('LiteTest PL contract', $links[1]['label']);
        LiteAssert::same(2, (int) $a->pdo->query("SELECT COUNT(*) FROM timeline_events WHERE subject_type = 'project' AND subject_id = {$id} AND type = 'linked'")->fetchColumn());
        LiteAssert::same([$id], array_map(fn ($r) => (int) $r['id'], $svc($a)->linkedTo($a->admin, 'contract', (int) $c['id'])));
        LiteAssert::same(1, $svc($a)->countLinkedTo($a->admin, 'deal', $dealId) >= 1 ? 1 : 0);
    },

    'refused targets: non-linkable types, unknown ids, another organization\'s deal/contract' => function () use ($boot, $svc, $refused) {
        $a = $boot();
        $a->as($a->admin);
        $id = (int) $svc($a)->create($a->admin, ['name' => 'LiteTest PL refuse'])['id'];
        foreach (['contact' => ProjectsLite::contactId($a), 'lead' => 1, 'project' => $id, 'nope' => 1] as $type => $target) {
            $refused(fn () => $svc($a)->link($a->admin, $id, $type, $target));
        }
        $refused(fn () => $svc($a)->link($a->admin, $id, 'deal', 999999999));
        $refused(fn () => $svc($a)->link($a->admin, $id, 'contract', 999999999));
        if ($a->otherOrgId !== null) {
            $a->pdo->prepare('INSERT INTO contracts (organization_id, title, owner_id, created_by_user_id) VALUES (?, ?, ?, ?)')->execute([$a->otherOrgId, 'LiteTest XORG contract', (int) $a->admin['id'], (int) $a->admin['id']]);
            $refused(fn () => $svc($a)->link($a->admin, $id, 'contract', (int) $a->pdo->lastInsertId()));
            $foreignDeal = $a->pdo->query("SELECT id FROM deals WHERE organization_id = {$a->otherOrgId} ORDER BY id LIMIT 1")->fetchColumn();
            if ($foreignDeal !== false) {
                $refused(fn () => $svc($a)->link($a->admin, $id, 'deal', (int) $foreignDeal));
            }
        }
        LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM project_links WHERE project_id = {$id}")->fetchColumn(), 'nothing linked');
    },

    'from deal: prefill (name, contact, company) and create-linked through the routes' => function () use ($boot, $deal) {
        $a = $boot();
        $a->as($a->admin);
        $dealId = $deal($a);
        $dealRow = $a->pdo->query("SELECT name, contact_id, company_id FROM deals WHERE id = {$dealId}")->fetch(\PDO::FETCH_ASSOC);
        $form = $a->request('GET', '/projects/create', ['from_deal' => (string) $dealId]);
        LiteAssert::same(200, $form['status']);
        LiteAssert::contains('name="source_type" value="deal"', $form['body']);
        LiteAssert::contains(htmlspecialchars((string) $dealRow['name'], ENT_QUOTES), $form['body'], 'name prefilled');
        LiteAssert::contains("href=\"/deals/{$dealId}\"", $form['body'], 'created-from notice links the deal');
        $r = $a->request('POST', '/projects', [], ['name' => 'LiteTest PL from deal', 'source_type' => 'deal', 'source_id' => (string) $dealId, 'contact_id' => (string) ($dealRow['contact_id'] ?? '')]);
        $id = (int) preg_replace('#^/projects/(\d+)$#', '$1', (string) $r['location']);
        LiteAssert::true($id > 0, 'created');
        LiteAssert::same(1, (int) $a->pdo->query("SELECT COUNT(*) FROM project_links WHERE project_id = {$id} AND linked_type = 'deal' AND linked_id = {$dealId}")->fetchColumn());
        LiteAssert::same('notfound', $a->outcome('GET', '/projects/create', ['from_deal' => '999999999']));
    },

    'from contract: prefill incl. the contract period; tampered source → 422 and nothing created' => function () use ($boot, $contract) {
        $a = $boot();
        $a->as($a->admin);
        $c = $contract($a, 'LiteTest PL source contract');
        $form = $a->request('GET', '/projects/create', ['from_contract' => (string) $c['id']]);
        LiteAssert::same(200, $form['status']);
        LiteAssert::contains('value="LiteTest PL source contract"', $form['body']);
        LiteAssert::contains('value="2026-02-01"', $form['body'], 'start date');
        LiteAssert::contains('value="2026-11-30"', $form['body'], 'expected end = contract end');
        $before = (int) $a->pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn();
        $bad = $a->request('POST', '/projects', [], ['name' => 'LiteTest PL tampered', 'source_type' => 'contract', 'source_id' => '999999999']);
        LiteAssert::same(422, $bad['status']);
        $bad2 = $a->request('POST', '/projects', [], ['name' => 'LiteTest PL tampered 2', 'source_type' => 'contact', 'source_id' => (string) ProjectsLite::contactId($a)]);
        LiteAssert::same(422, $bad2['status']);
        LiteAssert::same($before, (int) $a->pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn(), 'nothing created');
        $ok = $a->request('POST', '/projects', [], ['name' => 'LiteTest PL from contract', 'source_type' => 'contract', 'source_id' => (string) $c['id']]);
        LiteAssert::same(302, $ok['status']);
    },

    'panels: contact + company list projects; deal + contract list linked ones with New project and Link existing' => function () use ($boot, $svc, $deal, $contract) {
        $a = $boot();
        $a->as($a->admin);
        $contactId = ProjectsLite::contactId($a);
        $companyId = ProjectsLite::companyId($a);
        $svc($a)->create($a->admin, ['name' => 'LiteTest PL panel party', 'contact_id' => (string) $contactId, 'company_id' => (string) $companyId]);
        foreach (["/contacts/{$contactId}" => 'contact', "/companies/{$companyId}" => 'company'] as $path => $type) {
            $body = $a->request('GET', $path)['body'];
            LiteAssert::contains("data-related-panel=\"projects.{$type}\"", $body, $type);
            LiteAssert::contains('LiteTest PL panel party', $body, $type);
            LiteAssert::contains('/projects/create?' . $type . '_id=', $body, "{$type} New project prefill");
        }
        $c = $contract($a, 'LiteTest PL panel contract');
        $linkedOne = $svc($a)->create($a->admin, ['name' => 'LiteTest PL panel linked']);
        $svc($a)->link($a->admin, (int) $linkedOne['id'], 'contract', (int) $c['id']);
        $unlinked = $svc($a)->create($a->admin, ['name' => 'LiteTest PL panel candidate']);
        $body = $a->request('GET', "/contracts/{$c['id']}")['body'];
        LiteAssert::contains('data-related-panel="projects.contract"', $body);
        LiteAssert::contains('LiteTest PL panel linked', $body);
        LiteAssert::contains("/projects/create?from_contract={$c['id']}", $body);
        LiteAssert::contains('action="/projects/links"', $body, 'link-existing form');
        LiteAssert::contains('value="' . $unlinked['id'] . '"', $body, 'unlinked project offered');

        // Link the candidate through the panel's form.
        $r = $a->request('POST', '/projects/links', [], ['project_id' => (string) $unlinked['id'], 'linked_type' => 'contract', 'linked_id' => (string) $c['id']]);
        LiteAssert::same('/projects/' . $unlinked['id'], $r['location']);
        LiteAssert::same(2, $svc($a)->countLinkedTo($a->admin, 'contract', (int) $c['id']));

        $dealId = $deal($a);
        $body = $a->request('GET', "/deals/{$dealId}")['body'];
        LiteAssert::contains('data-related-panel="projects.deal"', $body);
        LiteAssert::contains("/projects/create?from_deal={$dealId}", $body);
    },

    'project page lists its links; unlink through the route; another link id → 404' => function () use ($boot, $svc, $contract) {
        $a = $boot();
        $a->as($a->admin);
        $c = $contract($a, 'LiteTest PL unlink contract');
        $id = (int) $svc($a)->create($a->admin, ['name' => 'LiteTest PL unlink'])['id'];
        $link = $svc($a)->link($a->admin, $id, 'contract', (int) $c['id']);
        $body = $a->request('GET', "/projects/{$id}")['body'];
        LiteAssert::contains('LiteTest PL unlink contract', $body);
        LiteAssert::contains("/projects/{$id}/links/{$link['id']}/delete", $body);
        LiteAssert::same('notfound', $a->outcome('POST', "/projects/{$id}/links/999999999/delete"));
        $a->request('POST', "/projects/{$id}/links/{$link['id']}/delete");
        LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM project_links WHERE project_id = {$id}")->fetchColumn());
    },

    'member (own edit): may link only projects they manage; the panel offers only those' => function () use ($boot, $svc, $contract) {
        $a = $boot();
        $a->as($a->admin);
        $c = $contract($a, 'LiteTest PL member contract');
        $adminProject = $svc($a)->create($a->admin, ['name' => 'LiteTest PL admin project']);
        $memberProject = $svc($a)->create($a->member, ['name' => 'LiteTest PL member project']);
        // Stage 3-A (approved Q4): linking also needs contracts.edit on the contract,
        // which the member holds on their OWN contracts only. On the admin's contract
        // the member may link nothing; the rest of this test runs on a member-owned one.
        $adminContract = $contract($a, 'LiteTest PL admin contract');
        $a->as($a->member);
        LiteAssert::same('forbidden', $a->outcome('POST', '/projects/links', [], ['project_id' => (string) $memberProject['id'], 'linked_type' => 'contract', 'linked_id' => (string) $adminContract['id']]));
        LiteAssert::same([], $svc($a)->linkableProjects($a->member, 'contract', (int) $adminContract['id']));
        $a->as($a->admin);
        $a->pdo->prepare('UPDATE contracts SET owner_id = ? WHERE id = ?')->execute([(int) $a->member['id'], (int) $c['id']]);
        $a->as($a->member);
        LiteAssert::same('forbidden', $a->outcome('POST', '/projects/links', [], ['project_id' => (string) $adminProject['id'], 'linked_type' => 'contract', 'linked_id' => (string) $c['id']]));
        $ids = array_map(fn ($p) => (int) $p['id'], $svc($a)->linkableProjects($a->member, 'contract', (int) $c['id']));
        LiteAssert::true(in_array((int) $memberProject['id'], $ids, true) && !in_array((int) $adminProject['id'], $ids, true), json_encode($ids));
        LiteAssert::same('ok', $a->outcome('POST', '/projects/links', [], ['project_id' => (string) $memberProject['id'], 'linked_type' => 'contract', 'linked_id' => (string) $c['id']]));
        $a->as($a->admin);
    },

    'Contracts off: contract link shows as plain stored label, from_contract 404; Projects keeps working' => function () use ($boot, $svc, $contract) {
        $a = $boot();
        $a->as($a->admin);
        $c = $contract($a, 'LiteTest PL off contract');
        $id = (int) $svc($a)->create($a->admin, ['name' => 'LiteTest PL off'])['id'];
        $svc($a)->link($a->admin, $id, 'contract', (int) $c['id']);
        $a->setOrgEntitled('contracts', false);
        $a->as($a->admin); // new "request": ModuleGate memoises per request
        try {
            $links = $svc($a)->links($a->admin, $id);
            LiteAssert::same(null, $links[0]['url']);
            LiteAssert::same('LiteTest PL off contract', $links[0]['label']);
            $body = $a->request('GET', "/projects/{$id}")['body'];
            LiteAssert::same(200, $a->request('GET', "/projects/{$id}")['status']);
            LiteAssert::notContains("href=\"/contracts/{$c['id']}\"", $body);
            LiteAssert::same('notfound', $a->outcome('GET', '/projects/create', ['from_contract' => (string) $c['id']]));
        } finally {
            $a->setOrgEntitled('contracts', true);
            $a->as($a->admin);
        }
        LiteAssert::same("/contracts/{$c['id']}", $svc($a)->links($a->admin, $id)[0]['url'], 'restored when re-enabled');
    },

    'Projects off: panels disappear from host pages, links are kept' => function () use ($boot, $svc, $contract) {
        $a = $boot();
        $a->as($a->admin);
        $c = $contract($a, 'LiteTest PL hidden');
        $id = (int) $svc($a)->create($a->admin, ['name' => 'LiteTest PL hidden project'])['id'];
        $svc($a)->link($a->admin, $id, 'contract', (int) $c['id']);
        $a->setOrgEntitled('projects', false);
        try {
            LiteAssert::notContains('data-related-panel="projects.contract"', $a->request('GET', "/contracts/{$c['id']}")['body']);
            LiteAssert::notContains('data-related-panel="projects.contact"', $a->request('GET', '/contacts/' . ProjectsLite::contactId($a))['body']);
        } finally {
            $a->setOrgEntitled('projects', true);
        }
        LiteAssert::same(1, (int) $a->pdo->query("SELECT COUNT(*) FROM project_links WHERE project_id = {$id}")->fetchColumn());
    },

    'Contracts own data is untouched: no contract_links row is ever written by Projects' => function () use ($boot) {
        $a = $boot();
        LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM contract_links WHERE linked_type = 'project'")->fetchColumn());
    },

    'rollback leaves no test data behind' => function () use ($boot) {
        $a = $boot();
        $a->rollBack();
        LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM projects WHERE name LIKE 'LiteTest%'")->fetchColumn());
        LiteAssert::same(0, (int) $a->pdo->query('SELECT COUNT(*) FROM project_links')->fetchColumn());
    },
];
