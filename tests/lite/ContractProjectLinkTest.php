<?php

declare(strict_types=1);

/**
 * Stage 3-A — Contract ↔ Project association (docs/project-finance-design.md).
 *
 * The association is the Projects module's existing project_links
 * (linked_type = 'contract'), N:N. Stage 3-A rules:
 * - link / unlink / from_contract need projects.edit on the project AND
 *   contracts.edit on the contract (record scope); unlink of a link whose
 *   contract was deleted needs projects.edit only;
 * - a NEW link: contract not terminated / cancelled, project not completed /
 *   cancelled; unlink in any status;
 * - idempotent link (no duplicate row, no duplicate timeline); unlink
 *   timeline only when a row was really deleted;
 * - timeline on both the project and the contract;
 * - no financial effect at all.
 * crm_local only, one transaction, rolled back.
 */

require_once __DIR__ . '/Support/LocalApp.php';
require_once __DIR__ . '/Support/ProjectsLite.php';

use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use Modules\Contracts\Services\ContractService;
use Modules\Finance\FinanceDocumentLinkRepository;
use Modules\Finance\InstallmentRepository;
use Modules\Finance\InvoiceRepository;
use Modules\Finance\ProformaRepository;
use Modules\Finance\CreditNoteRepository;
use Modules\Finance\Services\ContractBillingService;
use Modules\Finance\Services\InstallmentService;
use Modules\Finance\Services\InvoiceService;
use Modules\Finance\Services\ProformaService;
use Tests\Lite\Support\ProjectsLite;

$projectsBoot = ProjectsLite::booter(true);
$h = new \stdClass();
$h->ready = false;
$boot = function () use ($projectsBoot, $h) {
    $a = $projectsBoot();
    if (!$h->ready) {
        $finance = (int) $a->pdo->query("SELECT id FROM modules WHERE `key` = 'finance'")->fetchColumn();
        if ($finance > 0) {
            $a->pdo->prepare("UPDATE modules SET status = 'active' WHERE id = ?")->execute([$finance]);
            $a->pdo->prepare('DELETE FROM organization_modules WHERE organization_id = ? AND module_id = ?')->execute([$a->orgId, $finance]);
            $a->pdo->prepare('INSERT INTO organization_modules (organization_id, module_id, enabled) VALUES (?, ?, 1)')->execute([$a->orgId, $finance]);
        }
        $a->as($a->admin);
        $a->locale('en');
        $h->currency = (int) $a->pdo->query('SELECT id FROM currencies WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
        $h->ready = true;
    }

    return $a;
};
$svc = fn ($a) => ProjectsLite::service($a);

/** A contract in a stored status, owned by $owner (admin by default). */
$contract = function ($a, string $status = 'active', ?array $owner = null, ?string $endDate = null) use ($h): int {
    $a->as($a->admin);
    $id = (int) $a->container()->make(ContractService::class)->create($a->admin, ['title' => 'LiteTest S3A contract', 'currency_id' => (string) $h->currency])['id'];
    $a->pdo->prepare('UPDATE contracts SET status = ?, owner_id = ?, end_date = ? WHERE id = ?')->execute([$status, (int) ($owner ?? $a->admin)['id'], $endDate, $id]);

    return $id;
};
/** A project in a stored status, owned by $owner (admin by default). */
$project = function ($a, string $status = 'active', ?array $owner = null) use ($svc): int {
    $a->as($a->admin);
    $id = (int) $svc($a)->create($a->admin, ['name' => 'LiteTest S3A project ' . bin2hex(random_bytes(2))])['id'];
    $a->pdo->prepare('UPDATE projects SET status = ?, owner_id = ? WHERE id = ?')->execute([$status, (int) ($owner ?? $a->admin)['id'], $id]);

    return $id;
};
/** The member acting with a temporary role holding exactly $permissions ([key => scope]). */
$userWith = function ($a, array $permissions): array {
    $a->pdo->prepare('INSERT INTO roles (organization_id, `key`, name, is_system) VALUES (?, ?, ?, 0)')
        ->execute([$a->orgId, 'litetest_s3a_' . bin2hex(random_bytes(4)), 'LiteTest S3A']);
    $roleId = (int) $a->pdo->lastInsertId();
    foreach ($permissions as $key => $scope) {
        $a->pdo->prepare('INSERT INTO role_permissions (role_id, permission_key, scope) VALUES (?, ?, ?)')->execute([$roleId, $key, $scope]);
    }

    return array_merge($a->member, ['role_id' => $roleId]);
};
$both = ['projects.view' => 'organization', 'projects.edit' => 'organization', 'contracts.view' => 'organization', 'contracts.edit' => 'organization'];
$links = fn ($a, int $p, int $c): int => (int) $a->pdo->query("SELECT COUNT(*) FROM project_links WHERE project_id = {$p} AND linked_type = 'contract' AND linked_id = {$c}")->fetchColumn();
$linkId = fn ($a, int $p, int $c): int => (int) $a->pdo->query("SELECT id FROM project_links WHERE project_id = {$p} AND linked_type = 'contract' AND linked_id = {$c}")->fetchColumn();
$events = fn ($a, string $subject, int $id, string $type): array => $a->pdo->query("SELECT * FROM timeline_events WHERE subject_type = '{$subject}' AND subject_id = {$id} AND type = '{$type}' ORDER BY id")->fetchAll(\PDO::FETCH_ASSOC);
/** Runs $fn as $user (route helpers), restoring the admin afterwards. */
$as = function ($a, array $user, callable $fn) {
    $a->as($user);
    try {
        return $fn();
    } finally {
        $a->as($a->admin);
    }
};
$linkRoute = fn (int $p, int $c) => ['POST', '/projects/links', [], ['project_id' => (string) $p, 'linked_type' => 'contract', 'linked_id' => (string) $c]];
$refused = function (callable $fn, string $field, string $key): void {
    try {
        $fn();
    } catch (ValidationException $e) {
        LiteAssert::same([$key], $e->errors[$field] ?? null, json_encode($e->errors));

        return;
    }
    throw new LiteAssertionFailed('accepted');
};

$tests = [];

$tests['1. link: one row, idempotent, timeline on project AND contract exactly once'] = function () use ($boot, $svc, $contract, $project, $links, $events) {
    $a = $boot();
    $c = $contract($a);
    $p = $project($a);
    $first = $svc($a)->link($a->admin, $p, 'contract', $c);
    $again = $svc($a)->link($a->admin, $p, 'contract', $c);
    LiteAssert::same((int) $first['id'], (int) $again['id'], 'same link returned');
    LiteAssert::same(1, $links($a, $p, $c));
    LiteAssert::same(1, count($events($a, 'project', $p, 'linked')));
    $ce = $events($a, 'contract', $c, 'project_linked');
    LiteAssert::same(1, count($ce), 'contract-side event once');
    LiteAssert::contains('LiteTest S3A project', $ce[0]['description']);
    LiteAssert::same((int) $a->admin['id'], (int) $ce[0]['actor_user_id']);
    LiteAssert::same($a->orgId, (int) $ce[0]['organization_id']);
};

$tests['2. N:N kept: one contract ↔ many projects, one project ↔ many contracts'] = function () use ($boot, $svc, $contract, $project, $links) {
    $a = $boot();
    [$c1, $c2] = [$contract($a), $contract($a)];
    [$p1, $p2] = [$project($a), $project($a)];
    foreach ([[$p1, $c1], [$p2, $c1], [$p1, $c2]] as [$p, $c]) {
        $svc($a)->link($a->admin, $p, 'contract', $c);
    }
    LiteAssert::same([1, 1, 1], [$links($a, $p1, $c1), $links($a, $p2, $c1), $links($a, $p1, $c2)]);
    LiteAssert::same(2, count($svc($a)->linkedTo($a->admin, 'contract', $c1)));
};

$tests['3. view: the contract page lists the project; the project page lists the contract'] = function () use ($boot, $svc, $contract, $project) {
    $a = $boot();
    $c = $contract($a);
    $p = $project($a);
    $svc($a)->link($a->admin, $p, 'contract', $c);
    LiteAssert::contains('/projects/' . $p . '"', $a->request('GET', "/contracts/{$c}")['body']);
    LiteAssert::contains('/contracts/' . $c . '"', $a->request('GET', "/projects/{$p}")['body']);
};

$tests['4. unlink from the contract page: only the link goes; back to the contract; both timelines; return_to cannot redirect elsewhere'] = function () use ($boot, $svc, $contract, $project, $links, $linkId, $events) {
    $a = $boot();
    $c = $contract($a);
    $p = $project($a);
    $svc($a)->link($a->admin, $p, 'contract', $c);
    $r = $a->request('POST', "/projects/{$p}/links/" . $linkId($a, $p, $c) . '/delete', [], ['return_to' => "/contracts/{$c}"]);
    LiteAssert::same([302, "/contracts/{$c}"], [$r['status'], $r['location']]);
    LiteAssert::same(0, $links($a, $p, $c));
    LiteAssert::same(1, (int) $a->pdo->query("SELECT COUNT(*) FROM contracts WHERE id = {$c}")->fetchColumn(), 'contract kept');
    LiteAssert::same(1, (int) $a->pdo->query("SELECT COUNT(*) FROM projects WHERE id = {$p}")->fetchColumn(), 'project kept');
    LiteAssert::same(1, count($events($a, 'project', $p, 'unlinked')));
    LiteAssert::same(1, count($events($a, 'contract', $c, 'project_unlinked')));

    foreach (['https://evil.example/x', '//evil.example', '/contracts/' . ($c + 1), '/admin'] as $bad) {
        $svc($a)->link($a->admin, $p, 'contract', $c);
        $r = $a->request('POST', "/projects/{$p}/links/" . $linkId($a, $p, $c) . '/delete', [], ['return_to' => $bad]);
        LiteAssert::same("/projects/{$p}", $r['location'], "no redirect to {$bad}");
    }
};

$tests['5. duplicates: the database UNIQUE key is still there and rejects a second row'] = function () use ($boot, $svc, $contract, $project) {
    $a = $boot();
    $c = $contract($a);
    $p = $project($a);
    $svc($a)->link($a->admin, $p, 'contract', $c);
    try {
        (new \Modules\Projects\ProjectLinkRepository())->create($p, 'contract', $c, 'dup', (int) $a->admin['id']);
        throw new LiteAssertionFailed('duplicate row accepted');
    } catch (\PDOException $e) {
        LiteAssert::same(1062, $e->errorInfo[1] ?? null);
    }
};

$tests['6. invalid ids: unknown contract → 422, unknown project → 404; nothing written'] = function () use ($boot, $svc, $contract, $project, $refused) {
    $a = $boot();
    $before = (int) $a->pdo->query('SELECT COUNT(*) FROM project_links')->fetchColumn();
    $refused(fn () => $svc($a)->link($a->admin, $project($a), 'contract', 999999999), 'link', 'projects.error.link_target_invalid');
    LiteAssert::throws(NotFoundException::class, fn () => $svc($a)->link($a->admin, 999999999, 'contract', $contract($a)));
    LiteAssert::same('notfound', $a->outcome(...['POST', '/projects/links', [], ['project_id' => '999999999', 'linked_type' => 'contract', 'linked_id' => (string) $contract($a)]]));
    LiteAssert::same($before, (int) $a->pdo->query('SELECT COUNT(*) FROM project_links')->fetchColumn());
};

$tests['7. RBAC link: needs projects.edit on the project AND contracts.edit on the contract (route and service)'] = function () use ($boot, $svc, $contract, $project, $userWith, $both, $as, $linkRoute, $links) {
    $a = $boot();
    $c = $contract($a);
    $p = $project($a);
    $noProjectEdit = $userWith($a, array_diff_key($both, ['projects.edit' => 1]));
    $noContractEdit = $userWith($a, array_diff_key($both, ['contracts.edit' => 1]));
    $ownContractEdit = $userWith($a, ['contracts.edit' => 'own'] + $both);
    LiteAssert::same('forbidden', $as($a, $noProjectEdit, fn () => $a->outcome(...$linkRoute($p, $c))));
    LiteAssert::same('forbidden', $as($a, $noContractEdit, fn () => $a->outcome(...$linkRoute($p, $c))));
    LiteAssert::throws(AuthorizationException::class, fn () => $svc($a)->link($noContractEdit, $p, 'contract', $c));
    LiteAssert::throws(AuthorizationException::class, fn () => $svc($a)->link($ownContractEdit, $p, 'contract', $c), 'contracts.edit own, not their contract');
    // Record scope is the service's job: the route's blanket check passes these.
    $ownProjectEdit = $userWith($a, ['projects.edit' => 'own'] + $both);
    LiteAssert::same('forbidden', $as($a, $ownProjectEdit, fn () => $a->outcome(...$linkRoute($p, $c))), 'projects.edit own, not their project');
    $ownContractView = $userWith($a, ['contracts.view' => 'own'] + $both);
    $as($a, $ownContractView, fn () => $a->outcome(...$linkRoute($p, $c)));
    LiteAssert::throws(ValidationException::class, fn () => $svc($a)->link($ownContractView, $p, 'contract', $c), 'contract outside their view scope');
    LiteAssert::same(0, $links($a, $p, $c));
    $ok = $userWith($a, $both);
    LiteAssert::same('ok', $as($a, $ok, fn () => $a->outcome(...$linkRoute($p, $c))));
    LiteAssert::same(1, $links($a, $p, $c));
    // own-scope contracts.edit on their OWN contract works
    $mine = $contract($a, 'active', $ownContractEdit);
    $svc($a)->link($ownContractEdit, $p, 'contract', $mine);
    LiteAssert::same(1, $links($a, $p, $mine));
};

$tests['8. RBAC unlink: needs both permissions; refused unlink leaves the row and writes no timeline'] = function () use ($boot, $svc, $contract, $project, $userWith, $both, $as, $links, $linkId, $events) {
    $a = $boot();
    $c = $contract($a);
    $p = $project($a);
    $svc($a)->link($a->admin, $p, 'contract', $c);
    $id = $linkId($a, $p, $c);
    foreach (['projects.edit', 'contracts.edit'] as $missing) {
        $u = $userWith($a, array_diff_key($both, [$missing => 1]));
        LiteAssert::same('forbidden', $as($a, $u, fn () => $a->outcome('POST', "/projects/{$p}/links/{$id}/delete")), "without {$missing}");
    }
    LiteAssert::throws(AuthorizationException::class, fn () => $svc($a)->unlink($userWith($a, array_diff_key($both, ['contracts.edit' => 1])), $p, $id));
    // projects.edit own on someone else's project: the route's blanket check passes, the service refuses.
    $ownProjectEdit = $userWith($a, ['projects.edit' => 'own'] + $both);
    LiteAssert::same('forbidden', $as($a, $ownProjectEdit, fn () => $a->outcome('POST', "/projects/{$p}/links/{$id}/delete")));
    LiteAssert::throws(AuthorizationException::class, fn () => $svc($a)->unlink($ownProjectEdit, $p, $id));
    LiteAssert::same(1, $links($a, $p, $c));
    LiteAssert::same([0, 0], [count($events($a, 'project', $p, 'unlinked')), count($events($a, 'contract', $c, 'project_unlinked'))]);
    LiteAssert::same('ok', $as($a, $userWith($a, $both), fn () => $a->outcome('POST', "/projects/{$p}/links/{$id}/delete")));
    LiteAssert::same(0, $links($a, $p, $c));
};

$tests['9. RBAC view: without projects.view the contract page shows no project; without contracts.view the project page shows no contract link'] = function () use ($boot, $svc, $contract, $project, $userWith, $as) {
    $a = $boot();
    $c = $contract($a);
    $p = $project($a);
    $svc($a)->link($a->admin, $p, 'contract', $c);
    $noProjects = $userWith($a, ['contracts.view' => 'organization']);
    $page = $as($a, $noProjects, fn () => $a->request('GET', "/contracts/{$c}")['body']);
    LiteAssert::notContains('/projects/' . $p, $page);
    $noContracts = $userWith($a, ['projects.view' => 'organization']);
    $page = $as($a, $noContracts, fn () => $a->request('GET', "/projects/{$p}")['body']);
    LiteAssert::notContains('href="/contracts/' . $c . '"', $page, 'no link to a contract they may not view');
    LiteAssert::notContains('/links/', $page, 'no unlink control');
};

$tests['10. tenant: my contract + another organization\'s project, and the reverse → refused, nothing written'] = function () use ($boot, $svc, $contract, $project, $refused, $linkRoute) {
    $a = $boot();
    if ($a->otherOrgId === null) {
        throw new LiteSkip('only one organization');
    }
    $before = (int) $a->pdo->query('SELECT COUNT(*) FROM project_links')->fetchColumn();
    $myContract = $contract($a);
    $foreignProject = ProjectsLite::foreignProject($a);
    LiteAssert::throws(NotFoundException::class, fn () => $svc($a)->link($a->admin, $foreignProject, 'contract', $myContract));
    LiteAssert::same('notfound', $a->outcome(...$linkRoute($foreignProject, $myContract)));

    $myProject = $project($a);
    $foreignContract = $contract($a);
    $a->pdo->exec("UPDATE contracts SET organization_id = {$a->otherOrgId} WHERE id = {$foreignContract}");
    $refused(fn () => $svc($a)->link($a->admin, $myProject, 'contract', $foreignContract), 'link', 'projects.error.link_target_invalid');
    LiteAssert::same('ok', $a->outcome(...$linkRoute($myProject, $foreignContract)), 'route: 302 back with an error');
    LiteAssert::same($before, (int) $a->pdo->query('SELECT COUNT(*) FROM project_links')->fetchColumn(), 'nothing written');
};

$tests['11. tenant: another organization\'s association is neither listed nor removable (IDOR on the link id)'] = function () use ($boot, $contract, $project, $svc) {
    $a = $boot();
    if ($a->otherOrgId === null) {
        throw new LiteSkip('only one organization');
    }
    $c = $contract($a);
    $p = $project($a);
    // A row of the OTHER organization pointing at MY project and MY contract.
    $a->pdo->prepare("INSERT INTO project_links (organization_id, project_id, linked_type, linked_id, linked_label) VALUES (?, ?, 'contract', ?, 'XORG')")->execute([$a->otherOrgId, $p, $c]);
    $foreignLink = (int) $a->pdo->lastInsertId();
    LiteAssert::same([], $svc($a)->linkedTo($a->admin, 'contract', $c), 'not listed on my contract');
    LiteAssert::same([], $svc($a)->links($a->admin, $p), 'not listed on my project');
    LiteAssert::same('notfound', $a->outcome('POST', "/projects/{$p}/links/{$foreignLink}/delete"));
    LiteAssert::throws(NotFoundException::class, fn () => $svc($a)->unlink($a->admin, $p, $foreignLink));
    LiteAssert::same(1, (int) $a->pdo->query("SELECT COUNT(*) FROM project_links WHERE id = {$foreignLink}")->fetchColumn(), 'foreign row untouched');
    // And a link id of MY organization through another (foreign) project → 404.
    $mine = $project($a);
    $svc($a)->link($a->admin, $mine, 'contract', $c);
    $myLink = (int) $a->pdo->query("SELECT id FROM project_links WHERE project_id = {$mine} AND organization_id = {$a->orgId}")->fetchColumn();
    $foreignProject = ProjectsLite::foreignProject($a);
    LiteAssert::same('notfound', $a->outcome('POST', "/projects/{$foreignProject}/links/{$myLink}/delete"));
    LiteAssert::same(1, (int) $a->pdo->query("SELECT COUNT(*) FROM project_links WHERE id = {$myLink}")->fetchColumn());
};

$tests['12. contract status matrix: link in draft/pending/active/expired; refused when terminated/cancelled; unlink in every status'] = function () use ($boot, $svc, $contract, $project, $refused, $links, $linkId) {
    $a = $boot();
    $allowed = ['draft' => null, 'pending_approval' => null, 'active' => null, 'expired (computed)' => date('Y-m-d', strtotime('-1 day')), 'expired (stored)' => null];
    foreach ($allowed as $label => $end) {
        $status = str_starts_with($label, 'expired') ? ($end !== null ? 'active' : 'expired') : $label;
        $c = $contract($a, $status, null, $end);
        $p = $project($a);
        $svc($a)->link($a->admin, $p, 'contract', $c);
        LiteAssert::same(1, $links($a, $p, $c), $label);
    }
    foreach (['terminated', 'cancelled'] as $status) {
        $c = $contract($a, $status);
        $p = $project($a);
        $refused(fn () => $svc($a)->link($a->admin, $p, 'contract', $c), 'link', 'projects.error.closed_contract');
        LiteAssert::same(0, $links($a, $p, $c), $status);
    }
    foreach (['draft', 'pending_approval', 'active', 'expired', 'terminated', 'cancelled'] as $status) {
        $c = $contract($a);
        $p = $project($a);
        $svc($a)->link($a->admin, $p, 'contract', $c);
        $a->pdo->exec("UPDATE contracts SET status = '{$status}' WHERE id = {$c}");
        $svc($a)->unlink($a->admin, $p, $linkId($a, $p, $c));
        LiteAssert::same(0, $links($a, $p, $c), "unlink while {$status}");
    }
};

$tests['13. project status matrix: link in draft/planned/active/on_hold; refused when completed/cancelled; unlink in every status'] = function () use ($boot, $svc, $contract, $project, $refused, $links, $linkId) {
    $a = $boot();
    foreach (['draft', 'planned', 'active', 'on_hold'] as $status) {
        $c = $contract($a);
        $p = $project($a, $status);
        $svc($a)->link($a->admin, $p, 'contract', $c);
        LiteAssert::same(1, $links($a, $p, $c), $status);
    }
    foreach (['completed', 'cancelled'] as $status) {
        $c = $contract($a);
        $p = $project($a, $status);
        $refused(fn () => $svc($a)->link($a->admin, $p, 'contract', $c), 'link', 'projects.error.closed_project');
        LiteAssert::same(0, $links($a, $p, $c), $status);
    }
    foreach (['draft', 'planned', 'active', 'on_hold', 'completed', 'cancelled'] as $status) {
        $c = $contract($a);
        $p = $project($a);
        $svc($a)->link($a->admin, $p, 'contract', $c);
        $a->pdo->exec("UPDATE projects SET status = '{$status}' WHERE id = {$p}");
        $svc($a)->unlink($a->admin, $p, $linkId($a, $p, $c));
        LiteAssert::same(0, $links($a, $p, $c), "unlink while {$status}");
    }
};

$tests['14. from_contract: same rules (both permissions, open contract); creates project + link + both timelines'] = function () use ($boot, $contract, $userWith, $both, $as, $events, $links) {
    $a = $boot();
    $c = $contract($a);
    $noContractEdit = $userWith($a, array_diff_key($both, ['contracts.edit' => 1]) + ['projects.create' => 'organization']);
    LiteAssert::same('notfound', $as($a, $noContractEdit, fn () => $a->outcome('GET', '/projects/create', ['from_contract' => (string) $c])));
    $body = ['name' => 'LiteTest S3A from contract', 'source_type' => 'contract', 'source_id' => (string) $c];
    $before = (int) $a->pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn();
    LiteAssert::same('forbidden', $as($a, $noContractEdit, fn () => $a->outcome('POST', '/projects', [], $body)));
    LiteAssert::same($before, (int) $a->pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn(), 'nothing created');

    $closed = $contract($a, 'terminated');
    LiteAssert::same('notfound', $a->outcome('GET', '/projects/create', ['from_contract' => (string) $closed]));
    $r = $a->request('POST', '/projects', [], ['name' => 'LiteTest S3A closed', 'source_type' => 'contract', 'source_id' => (string) $closed]);
    LiteAssert::same(422, $r['status']);
    LiteAssert::same($before, (int) $a->pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn(), 'nothing created for a terminated contract');

    LiteAssert::same(200, $a->request('GET', '/projects/create', ['from_contract' => (string) $c])['status']);
    $r = $a->request('POST', '/projects', [], $body);
    $p = (int) basename((string) $r['location']);
    LiteAssert::same(1, $links($a, $p, $c));
    LiteAssert::same(1, count($events($a, 'project', $p, 'linked')));
    LiteAssert::same(1, count($events($a, 'contract', $c, 'project_linked')));
};

$tests['15. CSRF on link and unlink'] = function () use ($boot, $svc, $contract, $project, $linkId) {
    $a = $boot();
    $c = $contract($a);
    $p = $project($a);
    LiteAssert::same('csrf', $a->outcome('POST', '/projects/links', [], ['project_id' => (string) $p, 'linked_type' => 'contract', 'linked_id' => (string) $c, '_csrf' => 'bad']));
    $svc($a)->link($a->admin, $p, 'contract', $c);
    LiteAssert::same('csrf', $a->outcome('POST', "/projects/{$p}/links/" . $linkId($a, $p, $c) . '/delete', [], ['_csrf' => 'bad']));
};

$tests['16. contract panel: Unlink per project (CSRF + confirm) only when allowed; closed projects not offered; terminated → no new link / project'] = function () use ($boot, $svc, $contract, $project, $userWith, $both, $as, $linkId) {
    $a = $boot();
    $c = $contract($a);
    $p = $project($a);
    $done = $project($a, 'completed');
    $svc($a)->link($a->admin, $p, 'contract', $c);
    $page = $a->request('GET', "/contracts/{$c}")['body'];
    $form = 'action="/projects/' . $p . '/links/' . $linkId($a, $p, $c) . '/delete"';
    LiteAssert::contains($form, $page);
    LiteAssert::true((bool) preg_match('#' . preg_quote($form, '#') . '.*?name="_csrf".*?name="return_to" value="/contracts/' . $c . '".*?data-confirm#s', $page), 'csrf + return_to + data-confirm');
    LiteAssert::notContains('<option value="' . $done . '"', $page, 'completed project not offered');
    LiteAssert::notContains($form, $as($a, $userWith($a, array_diff_key($both, ['contracts.edit' => 1])), fn () => $a->request('GET', "/contracts/{$c}")['body']), 'no unlink without contracts.edit');

    $a->pdo->exec("UPDATE contracts SET status = 'terminated' WHERE id = {$c}");
    $page = $a->request('GET', "/contracts/{$c}")['body'];
    LiteAssert::notContains('action="/projects/links"', $page, 'terminated: no Link existing');
    LiteAssert::notContains('/projects/create?from_contract=' . $c, $page, 'terminated: no New project');
    LiteAssert::contains('/projects/create?from_contract=' . $c, (function () use ($a, $c) {
        $a->pdo->exec("UPDATE contracts SET status = 'active' WHERE id = {$c}");
        try {
            return $a->request('GET', "/contracts/{$c}")['body'];
        } finally {
            $a->pdo->exec("UPDATE contracts SET status = 'terminated' WHERE id = {$c}");
        }
    })(), 'active: New project offered');
    LiteAssert::contains($form, $page, 'terminated: unlink still offered');
};

$tests['17. project page: Unlink of a contract link hidden without contracts.edit; a deleted contract\'s link can be removed with projects.edit only'] = function () use ($boot, $svc, $contract, $project, $userWith, $both, $as, $linkId, $links, $events) {
    $a = $boot();
    $c = $contract($a);
    $p = $project($a);
    $svc($a)->link($a->admin, $p, 'contract', $c);
    $id = $linkId($a, $p, $c);
    $page = $as($a, $userWith($a, array_diff_key($both, ['contracts.edit' => 1])), fn () => $a->request('GET', "/projects/{$p}")['body']);
    LiteAssert::notContains("/links/{$id}/delete", $page);
    LiteAssert::contains("/links/{$id}/delete", $a->request('GET', "/projects/{$p}")['body'], 'admin sees it');

    $a->pdo->exec("DELETE FROM contracts WHERE id = {$c}");
    $projectsOnly = $userWith($a, ['projects.view' => 'organization', 'projects.edit' => 'organization']);
    $svc($a)->unlink($projectsOnly, $p, $id);
    LiteAssert::same(0, $links($a, $p, $c));
    LiteAssert::same(0, count($events($a, 'contract', $c, 'project_unlinked')), 'no event on a contract that no longer exists');
};

$tests['18. finance invariance: billing summary, invoices, installments and Finance links identical after link + unlink; no revenue'] = function () use ($boot, $h, $svc, $contract, $project, $linkId) {
    $a = $boot();
    if ((int) $a->pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'finance_installments'")->fetchColumn() !== 1) {
        throw new LiteSkip('Finance migration 0017 not applied');
    }
    $c = $contract($a);
    $k = $a->container();
    $installments = new InstallmentService(new InstallmentRepository(), new FinanceDocumentLinkRepository(), new ProformaRepository(), new InvoiceRepository(), new CreditNoteRepository(),
        $k->make(ProformaService::class), $k->make(InvoiceService::class), $k->make(\App\Infrastructure\Authorization\AuthorizationEngine::class),
        $k->make(\App\Application\Services\TimelineService::class), $k->make(\App\Infrastructure\Records\RecordTypeRegistry::class));
    $billing = new ContractBillingService($k->make(ProformaService::class), $k->make(InvoiceService::class), new FinanceDocumentLinkRepository(),
        $k->make(\App\Application\Services\TimelineService::class), $k->make(\App\Infrastructure\Records\RecordTypeRegistry::class), installments: $installments);
    $doc = ['currency_id' => (string) $h->currency, 'pricing_mode' => 'tax_exclusive', 'issue_date' => date('Y-m-d'), 'tax_profile_id' => '',
        'items' => [['description' => 'LiteTest S3A line', 'quantity' => '1', 'unit_price' => '1000', 'discount_type' => 'none', 'discount_value' => '0']]];
    $inv = $billing->createLinked($a->admin, 'invoice', $doc, 'contract', $c);
    $a->pdo->exec("UPDATE invoices SET status = 'issued', subtotal = 1000, tax_total = 0, grand_total = 1000, paid_total = 250 WHERE id = " . (int) $inv['id']);
    $installments->create($a->admin, $c, ['due_date' => '2030-01-01', 'amount' => '400']);
    $can = fn (string $key): bool => $k->make(\App\Infrastructure\Authorization\AuthorizationEngine::class)->can($a->admin, $key);
    $snapshot = fn () => [
        $billing->panelFor($a->admin, $c, $can),
        $installments->scheduleFor($a->admin, $installments->contract($a->admin, $c), $can),
        $a->pdo->query('SELECT id, status, grand_total, paid_total, currency_id FROM invoices ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC),
        $a->pdo->query('SELECT COUNT(*), COALESCE(SUM(amount), 0) FROM payments')->fetch(\PDO::FETCH_NUM),
        $a->pdo->query('SELECT COUNT(*), COALESCE(SUM(amount), 0) FROM credit_notes')->fetch(\PDO::FETCH_NUM),
        $a->pdo->query('SELECT * FROM finance_document_links ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC),
        $a->pdo->query('SELECT * FROM finance_installments ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC),
        $a->pdo->query("SELECT value, currency_id FROM contracts WHERE id = {$c}")->fetch(\PDO::FETCH_ASSOC),
    ];
    $before = $snapshot();
    $p1 = $project($a);
    $p2 = $project($a);
    $svc($a)->link($a->admin, $p1, 'contract', $c);
    $svc($a)->link($a->admin, $p2, 'contract', $c);
    LiteAssert::same($before, $snapshot(), 'nothing financial changed by linking');
    $svc($a)->unlink($a->admin, $p1, $linkId($a, $p1, $c));
    LiteAssert::same($before, $snapshot(), 'nothing financial changed by unlinking');
    LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM finance_document_links WHERE linked_type = 'project'")->fetchColumn(), 'no project attribution / revenue');
};

$tests['19. deal links unchanged: no contracts.edit or status rule applies to them'] = function () use ($boot, $svc, $project, $userWith) {
    $a = $boot();
    $deal = ProjectsLite::dealId($a);
    if ($deal === null) {
        throw new LiteSkip('needs a deal in crm_local');
    }
    $p = $project($a, 'completed');
    $svc($a)->link($a->admin, $p, 'deal', $deal);
    LiteAssert::same(1, (int) $a->pdo->query("SELECT COUNT(*) FROM project_links WHERE project_id = {$p} AND linked_type = 'deal'")->fetchColumn(), 'completed project may still link a deal (unchanged)');
};

$tests['20. five languages: new error messages exist and the contract panel renders without raw keys'] = function () use ($boot, $svc, $contract, $project) {
    $a = $boot();
    $c = $contract($a);
    $svc($a)->link($a->admin, $project($a), 'contract', $c);
    try {
        foreach (['en', 'fa', 'ar', 'tr', 'fr'] as $locale) {
            $lang = require dirname(__DIR__, 2) . "/lang/{$locale}.php";
            foreach (['projects.error.closed_contract', 'projects.error.closed_project', 'projects.unlink'] as $key) {
                LiteAssert::true(trim((string) ($lang[$key] ?? '')) !== '', "{$locale}: {$key}");
            }
            $a->locale($locale);
            $body = $a->request('GET', "/contracts/{$c}")['body'];
            LiteAssert::notContains('projects.error.', $body, $locale);
            LiteAssert::contains(htmlspecialchars($lang['projects.unlink'], ENT_QUOTES), $body, $locale);
        }
    } finally {
        $a->locale('en');
    }
};

$tests['rollback leaves no test data behind'] = function () use ($boot) {
    $a = $boot();
    $a->rollBack();
    LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM contracts WHERE title = 'LiteTest S3A contract'")->fetchColumn());
    LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM projects WHERE name LIKE 'LiteTest S3A%'")->fetchColumn());
    LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM roles WHERE `key` LIKE 'litetest_s3a_%'")->fetchColumn());
};

return $tests;
