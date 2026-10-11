<?php

declare(strict_types=1);

/**
 * Stage 5.1 — expense project allocation + contract reference
 * (docs/project-finance-design.md).
 *
 * Project = financial allocation (the existing project_id, E7 / E9 checks);
 * Contract = REFERENCE only (contract_id): it never changes any figure and a
 * project is never derived from it (Contract ↔ Project is N:N). The general
 * form lists only projects / contracts the server would accept; every
 * submitted id is re-checked. Stage 5 profit / margin / budget formulas are
 * unchanged: only project_id counts, each row once.
 *
 * crm_local only, one transaction, rolled back.
 */

require_once __DIR__ . '/Support/LocalApp.php';
require_once __DIR__ . '/Support/ProjectsLite.php';

use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\ValidationException;
use Modules\Finance\BudgetRepository;
use Modules\Finance\ExpenseRepository;
use Modules\Finance\ProjectRevenueRepository;
use Modules\Finance\Services\BudgetService;
use Modules\Finance\Services\ExpenseService;
use Modules\Finance\Services\ProjectProfitService;
use Tests\Lite\Support\ProjectsLite;

$projectsBoot = ProjectsLite::booter(true);
$h = new \stdClass();
$h->ready = false;
$boot = function () use ($projectsBoot, $h) {
    $a = $projectsBoot();
    if (!$h->ready) {
        $cols = $a->pdo->query('SHOW COLUMNS FROM finance_expenses')->fetchAll(\PDO::FETCH_COLUMN);
        if (!in_array('contract_id', $cols, true)) {
            throw new LiteSkip('Finance migration 0022 not applied to crm_local');
        }
        $finance = (int) $a->pdo->query("SELECT id FROM modules WHERE `key` = 'finance'")->fetchColumn();
        $a->pdo->prepare("UPDATE modules SET status = 'active' WHERE id = ?")->execute([$finance]);
        $a->pdo->prepare('DELETE FROM organization_modules WHERE organization_id = ? AND module_id = ?')->execute([$a->orgId, $finance]);
        $a->pdo->prepare('INSERT INTO organization_modules (organization_id, module_id, enabled) VALUES (?, ?, 1)')->execute([$a->orgId, $finance]);
        $a->pdo->prepare("DELETE FROM financial_periods WHERE organization_id = ? AND status = 'closed'")->execute([$a->orgId]);
        $a->pdo->prepare("INSERT INTO financial_periods (organization_id, starts_on, ends_on, status) VALUES (?, '2001-01-01', '2001-12-31', 'closed')")->execute([$a->orgId]);
        $a->as($a->admin);
        $a->locale('en');
        $h->cur = (int) $a->pdo->query('SELECT id FROM currencies WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
        $h->ready = true;
    }

    return $a;
};
$k = fn ($a) => $a->container();
$engine = fn ($a) => $k($a)->make(\App\Infrastructure\Authorization\AuthorizationEngine::class);
$registry = fn ($a) => $k($a)->make(\App\Infrastructure\Records\RecordTypeRegistry::class);
/** Built with the same arguments FinanceModule passes (the route tests cover the real wiring). */
$svc = fn ($a): ExpenseService => new ExpenseService(new ExpenseRepository(), $engine($a), $k($a)->make(\App\Application\Services\TimelineService::class),
    $k($a)->make(\App\Application\Services\AttachmentService::class), $registry($a));
$profitSvc = fn ($a): ProjectProfitService => new ProjectProfitService(new ProjectRevenueRepository(), new ExpenseRepository(), $svc($a), $engine($a));
$budgetSvc = fn ($a): BudgetService => new BudgetService(new BudgetRepository(), $engine($a), $k($a)->make(\App\Application\Services\TimelineService::class), new ExpenseRepository(), $svc($a), $registry($a));
$input = fn ($a, array $extra = []) => $extra + [
    'category' => 'procurement', 'description' => 'LiteTest S51 expense', 'reference_number' => '',
    'expense_date' => date('Y-m-d'), 'amount' => '100', 'currency_id' => (string) (int) $a->pdo->query('SELECT id FROM currencies WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn(),
    'payee_contact_id' => '', 'payee_company_id' => '',
];
$project = function ($a, string $status = 'active', ?array $owner = null): int {
    $a->as($a->admin);
    $id = (int) ProjectsLite::service($a)->create($a->admin, ['name' => 'LiteTest S51 project ' . bin2hex(random_bytes(2))])['id'];
    $a->pdo->prepare('UPDATE projects SET status = ?, owner_id = ? WHERE id = ?')->execute([$status, (int) ($owner ?? $a->admin)['id'], $id]);

    return $id;
};
$contract = function ($a, string $status = 'active', ?array $owner = null, ?int $org = null): int {
    $owner ??= $a->admin;
    $a->pdo->prepare("INSERT INTO contracts (organization_id, contract_number, title, owner_id, status, created_by_user_id) VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([$org ?? $a->orgId, 'LT51-' . bin2hex(random_bytes(3)), 'LiteTest S51 contract', (int) $owner['id'], $status, (int) $owner['id']]);

    return (int) $a->pdo->lastInsertId();
};
$userWith = function ($a, array $permissions): array {
    $a->pdo->prepare('INSERT INTO roles (organization_id, `key`, name, is_system) VALUES (?, ?, ?, 0)')
        ->execute([$a->orgId, 'litetest_s51_' . bin2hex(random_bytes(4)), 'LiteTest S51']);
    $roleId = (int) $a->pdo->lastInsertId();
    foreach ($permissions as $key => $scope) {
        $a->pdo->prepare('INSERT INTO role_permissions (role_id, permission_key, scope) VALUES (?, ?, ?)')->execute([$roleId, $key, $scope]);
    }

    return array_merge($a->member, ['role_id' => $roleId]);
};
$full = ['finance.expenses.view' => 'organization', 'finance.expenses.manage' => 'organization', 'projects.view' => 'organization', 'projects.edit' => 'organization', 'contracts.view' => 'organization'];
$as = function ($a, array $user, callable $fn) {
    $a->as($user);
    try {
        return $fn();
    } finally {
        $a->as($a->admin);
    }
};
$row = fn ($a, int $id): array => $a->pdo->query("SELECT * FROM finance_expenses WHERE id = {$id}")->fetch(\PDO::FETCH_ASSOC);
$idOf = fn (array $r): int => (int) basename((string) $r['location']);
$post = fn ($a, array $body): array => $a->request('POST', '/finance/expenses', [], $body);
$edit = fn ($a, int $id, array $body): array => $a->request('POST', "/finance/expenses/{$id}", [], $body);
/** Stage 5 actual cost of a project in the test currency (profit service, as the admin), or '0'. */
$costOf = function ($a, int $p) use ($profitSvc, $h): string {
    $all = $profitSvc($a)->profitFor($a->admin, (new \Modules\Projects\ProjectRepository())->findById($p));

    return $all[$h->cur]['cost'] ?? '0';
};
$costsPanel = function ($a, int $p) use ($svc, $engine, $h): string {
    $data = $svc($a)->projectPanel($a->admin, $svc($a)->viewableProject($a->admin, $p), fn (string $key): bool => $engine($a)->can($a->admin, $key));

    return $data['totals'][$h->cur] ?? '0';
};
$budgetActual = function ($a, int $p) use ($budgetSvc, $h): string {
    $data = $budgetSvc($a)->panel($a->admin, $budgetSvc($a)->project($a->admin, $p));

    return $data['currencies'][$h->cur]['total']['actual'] ?? '0';
};
$is = fn (string $expected, string $actual, string $msg = '') => LiteAssert::same(0, bccomp($expected, $actual, 18), "{$msg} expected {$expected}, got {$actual}");
$events = fn ($a, string $subject, int $id): string => json_encode($a->pdo->query("SELECT type, description FROM timeline_events WHERE subject_type = '{$subject}' AND subject_id = {$id} ORDER BY id")->fetchAll(\PDO::FETCH_ASSOC));

$tests = [];

$tests['1. general expense, no project and no contract: unallocated, affects no project'] = function () use ($boot, $project, $post, $input, $idOf, $row, $costOf) {
    $a = $boot();
    $p = $project($a);
    $id = $idOf($post($a, $input($a, ['project_id' => '', 'contract_id' => '', 'amount' => '111'])));
    LiteAssert::same([null, null], [$row($a, $id)['project_id'], $row($a, $id)['contract_id']]);
    LiteAssert::same(0, bccomp('0', $costOf($a, $p), 18));
};

$tests['2. general expense with project only: allocated, in Stage 5 actual cost / costs panel / budget actual'] = function () use ($boot, $project, $post, $input, $idOf, $row, $costOf, $costsPanel, $budgetActual, $is) {
    $a = $boot();
    $p = $project($a);
    $id = $idOf($post($a, $input($a, ['project_id' => (string) $p, 'contract_id' => '', 'amount' => '250'])));
    LiteAssert::same([$p, null], [(int) $row($a, $id)['project_id'], $row($a, $id)['contract_id']]);
    $is('250', $costOf($a, $p), 'profit cost');
    $is('250', $costsPanel($a, $p), 'project costs panel');
    $is('250', $budgetActual($a, $p), 'budget actual');
};

$tests['3. general expense with contract only: reference stored, NO project inferred even when the contract is linked to a project, no project figure moves'] = function () use ($boot, $project, $contract, $post, $input, $idOf, $row, $costOf, $costsPanel, $budgetActual, $is, $events) {
    $a = $boot();
    $p = $project($a);
    $c = $contract($a);
    $a->pdo->prepare("INSERT INTO project_links (organization_id, project_id, linked_type, linked_id, linked_label) VALUES (?, ?, 'contract', ?, 'LiteTest S51')")->execute([$a->orgId, $p, $c]);
    $id = $idOf($post($a, $input($a, ['project_id' => '', 'contract_id' => (string) $c, 'amount' => '333'])));
    LiteAssert::same([null, $c], [$row($a, $id)['project_id'], (int) $row($a, $id)['contract_id']]);
    $is('0', $costOf($a, $p), 'profit cost');
    $is('0', $costsPanel($a, $p), 'costs panel');
    $is('0', $budgetActual($a, $p), 'budget actual');
    LiteAssert::contains('Contract reference set (reference only)', $events($a, 'expense', $id));
    LiteAssert::same('[]', $events($a, 'contract', $c), 'nothing written on the contract');
};

$tests['4. general expense with project + contract: counted for the project exactly once'] = function () use ($boot, $project, $contract, $post, $input, $idOf, $row, $costOf, $budgetActual, $is) {
    $a = $boot();
    $p = $project($a);
    $c = $contract($a);
    $id = $idOf($post($a, $input($a, ['project_id' => (string) $p, 'contract_id' => (string) $c, 'amount' => '400'])));
    LiteAssert::same([$p, $c], [(int) $row($a, $id)['project_id'], (int) $row($a, $id)['contract_id']]);
    $is('400', $costOf($a, $p));
    $is('400', $budgetActual($a, $p));
};

$tests['5. Project → New Expense: project fixed (hidden, no selector), contract still selectable; the fixed project is re-checked on the server'] = function () use ($boot, $project, $contract, $post, $input, $idOf, $row, $userWith, $full, $as) {
    $a = $boot();
    $p = $project($a);
    $c = $contract($a);
    $form = $a->request('GET', '/finance/expenses/create', ['from_project' => (string) $p])['body'];
    LiteAssert::contains('name="project_id" value="' . $p . '"', $form);
    LiteAssert::contains('name="project_fixed" value="1"', $form);
    LiteAssert::notContains('id="project_id"', $form, 'no project selector in the project flow');
    LiteAssert::contains('id="contract_id"', $form);
    $id = $idOf($post($a, $input($a, ['project_id' => (string) $p, 'project_fixed' => '1', 'contract_id' => (string) $c])));
    LiteAssert::same([$p, $c], [(int) $row($a, $id)['project_id'], (int) $row($a, $id)['contract_id']]);
    $noEdit = $userWith($a, array_diff_key($full, ['projects.edit' => 1]));
    LiteAssert::same('forbidden', $as($a, $noEdit, fn () => $a->outcome('POST', '/finance/expenses', [], $input($a, ['project_id' => (string) $p, 'project_fixed' => '1']))), 'project_fixed grants nothing');
    // a validation error keeps the project fixed
    $r = $post($a, $input($a, ['project_id' => (string) $p, 'project_fixed' => '1', 'amount' => '-1']));
    LiteAssert::same(422, $r['status']);
    LiteAssert::notContains('id="project_id"', $r['body']);
};

$tests['6–8. edit: add a project, remove it, move A → B (E7 on both); a missing selector keeps the value'] = function () use ($boot, $project, $post, $edit, $input, $idOf, $row, $userWith, $full, $as, $svc, $costOf, $is) {
    $a = $boot();
    $pa = $project($a);
    $pb = $project($a);
    $id = $idOf($post($a, $input($a, ['project_id' => '', 'amount' => '70'])));
    LiteAssert::same(302, $edit($a, $id, $input($a, ['project_id' => (string) $pa, 'amount' => '70']))['status'], 'add');
    LiteAssert::same($pa, (int) $row($a, $id)['project_id']);
    $is('70', $costOf($a, $pa));
    LiteAssert::same(302, $edit($a, $id, $input($a, ['amount' => '70']))['status'], 'no selector posted');
    LiteAssert::same($pa, (int) $row($a, $id)['project_id'], 'kept');
    LiteAssert::same(302, $edit($a, $id, $input($a, ['project_id' => (string) $pb, 'amount' => '70']))['status'], 'move');
    LiteAssert::same($pb, (int) $row($a, $id)['project_id']);
    $is('0', $costOf($a, $pa), 'left A');
    $is('70', $costOf($a, $pb), 'now in B');
    LiteAssert::same(302, $edit($a, $id, $input($a, ['project_id' => '', 'amount' => '70']))['status'], 'remove');
    LiteAssert::same(null, $row($a, $id)['project_id']);
    $is('0', $costOf($a, $pb));
    // E7 on both: own-scope projects.edit can't move an expense away from someone else's project
    $svc($a)->update($a->admin, $id, $input($a, ['project_id' => (string) $pa, 'amount' => '70']));
    $ownProjects = $userWith($a, ['projects.edit' => 'own'] + $full);
    $theirs = $project($a, 'active', $ownProjects);
    LiteAssert::same('forbidden', $as($a, $ownProjects, fn () => $a->outcome('POST', "/finance/expenses/{$id}", [], $input($a, ['project_id' => (string) $theirs, 'amount' => '70']))));
    LiteAssert::same($pa, (int) $row($a, $id)['project_id']);
    $form = $a->request('GET', "/finance/expenses/{$id}/edit")['body'];
    LiteAssert::contains('<option value="' . $pa . '" selected', $form, 'current project selected');
};

$tests['9–12. financial semantics: contract-only never in any project; project-only is; project + contract once; no project → not in actual cost'] = function () use ($boot, $project, $contract, $svc, $input, $costOf, $costsPanel, $budgetActual, $is) {
    $a = $boot();
    $p = $project($a);
    $c = $contract($a);
    $a->pdo->prepare("INSERT INTO project_links (organization_id, project_id, linked_type, linked_id, linked_label) VALUES (?, ?, 'contract', ?, 'LiteTest S51')")->execute([$a->orgId, $p, $c]);
    $svc($a)->create($a->admin, $input($a, ['contract_id' => (string) $c, 'amount' => '1000']));        // 9: contract only
    $svc($a)->create($a->admin, $input($a, ['amount' => '2000']));                                      // 12: no project
    $svc($a)->create($a->admin, $input($a, ['project_id' => (string) $p, 'amount' => '30']));          // 10: project only
    $svc($a)->create($a->admin, $input($a, ['project_id' => (string) $p, 'contract_id' => (string) $c, 'amount' => '7'])); // 11
    foreach ([[$costOf, 'profit'], [$costsPanel, 'costs panel'], [$budgetActual, 'budget actual']] as [$fn, $what]) {
        $is('37', $fn($a, $p), $what);
    }
};

$tests['13–14. corrections copy project AND contract (frozen while corrections exist); void excluded; the contract never adds anything'] = function () use ($boot, $project, $contract, $svc, $input, $row, $costOf, $is) {
    $a = $boot();
    $p = $project($a);
    $c = $contract($a);
    $c2 = $contract($a);
    $orig = (int) $svc($a)->create($a->admin, $input($a, ['project_id' => (string) $p, 'contract_id' => (string) $c, 'amount' => '100']))['id'];
    $a->pdo->exec("UPDATE finance_expenses SET expense_date = '2001-06-01' WHERE id = {$orig}");
    $corr = (int) $svc($a)->createCorrection($a->admin, $orig, ['correction_direction' => 'decrease', 'contract_id' => (string) $c2] + $input($a, ['amount' => '40']))['id'];
    LiteAssert::same([$p, $c], [(int) $row($a, $corr)['project_id'], (int) $row($a, $corr)['contract_id']], 'copied from the original, not the form');
    $is('60', $costOf($a, $p), 'net once: 100 − 40');
    $voided = (int) $svc($a)->create($a->admin, $input($a, ['project_id' => (string) $p, 'contract_id' => (string) $c, 'amount' => '999']))['id'];
    $svc($a)->void($a->admin, $voided, 'mistake');
    $is('60', $costOf($a, $p), 'void excluded');
    // the original's period reopens: its contract is frozen like its project
    $a->pdo->exec("UPDATE financial_periods SET status = 'open' WHERE organization_id = {$a->orgId} AND starts_on = '2001-01-01'");
    try {
        LiteAssert::throws(ValidationException::class, fn () => $svc($a)->update($a->admin, $orig, $input($a, ['expense_date' => '2001-06-01', 'project_id' => (string) $p, 'contract_id' => (string) $c2])));
        LiteAssert::throws(ValidationException::class, fn () => $svc($a)->update($a->admin, $orig, $input($a, ['expense_date' => '2001-06-01', 'project_id' => (string) $p, 'remove_contract' => '1'])));
    } finally {
        $a->pdo->exec("UPDATE financial_periods SET status = 'closed' WHERE organization_id = {$a->orgId} AND starts_on = '2001-01-01'");
    }
    LiteAssert::same($c, (int) $row($a, $orig)['contract_id']);
};

$tests['15. closed period: adding a project or a contract to a closed-period expense is refused; nothing changes'] = function () use ($boot, $project, $contract, $svc, $input, $row) {
    $a = $boot();
    $p = $project($a);
    $c = $contract($a);
    $id = (int) $svc($a)->create($a->admin, $input($a))['id'];
    $a->pdo->exec("UPDATE finance_expenses SET expense_date = '2001-06-01' WHERE id = {$id}");
    foreach ([['project_id' => (string) $p], ['contract_id' => (string) $c]] as $change) {
        try {
            $svc($a)->update($a->admin, $id, $input($a, $change + ['expense_date' => '2001-06-01']));
            throw new LiteAssertionFailed('accepted in a closed period');
        } catch (ValidationException $e) {
            LiteAssert::true(isset($e->errors['expense_date']), json_encode($e->errors));
        }
    }
    LiteAssert::same([null, null], [$row($a, $id)['project_id'], $row($a, $id)['contract_id']]);
};

$tests['16. unauthorized project: not offered (no projects.edit, other owner\'s own-scope, cancelled) and refused when posted'] = function () use ($boot, $project, $svc, $input, $userWith, $full, $as, $post) {
    $a = $boot();
    $p = $project($a);
    $cancelled = $project($a, 'cancelled');
    LiteAssert::true(isset($svc($a)->projectOptions($a->admin)[$p]));
    LiteAssert::false(isset($svc($a)->projectOptions($a->admin)[$cancelled]), 'cancelled not offered');
    $noEdit = $userWith($a, array_diff_key($full, ['projects.edit' => 1]));
    LiteAssert::same([], $svc($a)->projectOptions($noEdit), 'no projects.edit: nothing offered');
    LiteAssert::same('forbidden', $as($a, $noEdit, fn () => $a->outcome('POST', '/finance/expenses', [], $input($a, ['project_id' => (string) $p]))));
    $ownView = $userWith($a, ['projects.view' => 'own'] + $full);
    LiteAssert::false(isset($svc($a)->projectOptions($ownView)[$p]), 'own scope: not someone else\'s');
    LiteAssert::same(422, $as($a, $ownView, fn () => $post($a, $input($a, ['project_id' => (string) $p]))['status']));
    LiteAssert::same(422, $post($a, $input($a, ['project_id' => (string) $cancelled]))['status']);
    $form = $as($a, $noEdit, fn () => $a->request('GET', '/finance/expenses/create')['body']);
    LiteAssert::notContains('<option value="' . $p . '"', $form);
};

$tests['17. unauthorized contract: not offered (no contracts.view, own scope of another owner) and refused when posted; any status allowed'] = function () use ($boot, $contract, $svc, $input, $userWith, $full, $as, $post) {
    $a = $boot();
    $c = $contract($a);
    $terminated = $contract($a, 'terminated');
    LiteAssert::true(isset($svc($a)->contractOptions($a->admin)[$c]) && isset($svc($a)->contractOptions($a->admin)[$terminated]), 'any status is a valid reference');
    $noContracts = $userWith($a, array_diff_key($full, ['contracts.view' => 1]));
    LiteAssert::false($svc($a)->contractsAvailable($noContracts));
    LiteAssert::notContains('id="contract_id"', $as($a, $noContracts, fn () => $a->request('GET', '/finance/expenses/create')['body']));
    LiteAssert::same(422, $as($a, $noContracts, fn () => $post($a, $input($a, ['contract_id' => (string) $c]))['status']));
    $ownContracts = $userWith($a, ['contracts.view' => 'own'] + $full);
    LiteAssert::false(isset($svc($a)->contractOptions($ownContracts)[$c]));
    LiteAssert::same(422, $as($a, $ownContracts, fn () => $post($a, $input($a, ['contract_id' => (string) $c]))['status']));
    $mine = $contract($a, 'active', $ownContracts);
    LiteAssert::true(isset($svc($a)->contractOptions($ownContracts)[$mine]));
    LiteAssert::same(302, $as($a, $ownContracts, fn () => $post($a, $input($a, ['contract_id' => (string) $mine]))['status']));
    LiteAssert::same(302, $post($a, $input($a, ['contract_id' => (string) $terminated]))['status']);
};

$tests['18. cross-organization project / contract: never offered, refused when posted (and on edit)'] = function () use ($boot, $contract, $svc, $input, $post, $edit, $idOf, $row) {
    $a = $boot();
    if ($a->otherOrgId === null) {
        throw new LiteSkip('only one organization');
    }
    $fp = ProjectsLite::foreignProject($a);
    $owner = $a->pdo->query("SELECT id FROM users WHERE organization_id = {$a->otherOrgId} ORDER BY id LIMIT 1")->fetchColumn();
    $fc = $contract($a, 'active', ['id' => $owner === false ? $a->admin['id'] : (int) $owner], $a->otherOrgId);
    LiteAssert::false(isset($svc($a)->projectOptions($a->admin)[$fp]));
    LiteAssert::false(isset($svc($a)->contractOptions($a->admin)[$fc]));
    LiteAssert::same(422, $post($a, $input($a, ['project_id' => (string) $fp]))['status']);
    LiteAssert::same(422, $post($a, $input($a, ['contract_id' => (string) $fc]))['status']);
    $id = $idOf($post($a, $input($a)));
    LiteAssert::same(422, $edit($a, $id, $input($a, ['contract_id' => (string) $fc]))['status']);
    LiteAssert::same(null, $row($a, $id)['contract_id']);
};

$tests['contract module unavailable: no selector, a posted contract is refused, an existing reference is kept on edit'] = function () use ($boot, $contract, $svc, $input, $post, $edit, $idOf, $row) {
    $a = $boot();
    $c = $contract($a);
    $id = $idOf($post($a, $input($a, ['contract_id' => (string) $c])));
    $a->setOrgEntitled('contracts', false);
    try {
        LiteAssert::notContains('id="contract_id"', $a->request('GET', '/finance/expenses/create')['body']);
        LiteAssert::same(422, $post($a, $input($a, ['contract_id' => (string) $c]))['status']);
        LiteAssert::same(302, $edit($a, $id, $input($a, ['amount' => '55']))['status'], 'other edits still work');
        LiteAssert::same($c, (int) $row($a, $id)['contract_id'], 'reference kept');
    } finally {
        $a->setOrgEntitled('contracts', true);
    }
};

$tests['contract reference changes no revenue / billing / installment / invoice / payment / credit / report figure; project timeline shows no contract'] = function () use ($boot, $project, $contract, $svc, $input, $engine, $k, $events) {
    $a = $boot();
    $p = $project($a);
    $c = $contract($a);
    $snap = fn () => [
        $a->pdo->query('SELECT id, status, grand_total, paid_total FROM invoices ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC),
        $a->pdo->query('SELECT COUNT(*), COALESCE(SUM(amount), 0) FROM payments')->fetch(\PDO::FETCH_NUM),
        $a->pdo->query('SELECT COUNT(*), COALESCE(SUM(amount), 0) FROM credit_notes')->fetch(\PDO::FETCH_NUM),
        $a->pdo->query('SELECT * FROM finance_document_links ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC),
        $a->pdo->query('SELECT * FROM finance_installments ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC),
        $a->pdo->query("SELECT * FROM contracts WHERE id = {$c}")->fetch(\PDO::FETCH_ASSOC),
        $a->pdo->query('SELECT * FROM project_links ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC),
    ];
    $before = $snap();
    $id = (int) $svc($a)->create($a->admin, $input($a, ['project_id' => (string) $p, 'contract_id' => (string) $c, 'amount' => '4321']))['id'];
    $svc($a)->update($a->admin, $id, $input($a, ['amount' => '4321', 'remove_contract' => '1']));
    LiteAssert::same($before, $snap());
    LiteAssert::notContains('ontract', $events($a, 'project', $p), 'project timeline has no contract detail');
    LiteAssert::contains('Contract reference removed', $events($a, 'expense', $id));
};

$tests['UI: approved field order, the contract help note directly under the contract field (subtle hint, not an alert), detail page shows the reference; 5 languages'] = function () use ($boot, $contract, $svc, $input) {
    $a = $boot();
    $c = $contract($a);
    $id = (int) $svc($a)->create($a->admin, $input($a, ['contract_id' => (string) $c]))['id'];
    try {
        foreach (['en', 'fa', 'ar', 'tr', 'fr'] as $locale) {
            $a->locale($locale);
            $lang = require dirname(__DIR__, 2) . "/lang/{$locale}.php";
            $page = $a->request('GET', '/finance/expenses/create')['body'];
            $form = substr($page, (int) strpos($page, '<section class="card card--form">')); // the expense form only, not the layout's own forms
            $order = array_map(fn (string $needle) => strpos($form, $needle), ['id="amount"', 'id="currency_id"', 'id="category"', 'id="expense_date"', 'id="payee_contact_id"', 'id="project_id"', 'id="contract_id"', 'id="description"', 'type="submit"']);
            LiteAssert::false(in_array(false, $order, true), "{$locale}: every field present");
            $sorted = $order;
            sort($sorted);
            LiteAssert::same($sorted, $order, "{$locale}: amount, currency, category, date, payee, project, contract, description, save");
            $note = htmlspecialchars($lang['finance.expense.contract_note'], ENT_QUOTES);
            LiteAssert::true(preg_match('#id="contract_id".*?</select>\s*<div class="field-hint" id="contract_note">.*?' . preg_quote($note, '#') . '#s', $form) === 1, "{$locale}: note right under the contract field");
            LiteAssert::notContains('finance.expense.', $form, "{$locale}: raw key");
            $show = $a->request('GET', "/finance/expenses/{$id}")['body'];
            LiteAssert::contains(htmlspecialchars($lang['finance.expense.contract_reference'], ENT_QUOTES), $show, $locale);
            LiteAssert::notContains('finance.expense.', $show, "{$locale}: raw key");
        }
        $fa = require dirname(__DIR__, 2) . '/lang/fa.php';
        LiteAssert::same('توجه: تخصیص هزینه به قرارداد به‌تنهایی در محاسبات مالی و سودآوری اثر ندارد. هزینه فقط در صورت تخصیص به یک پروژه، در محاسبات مالی و سودآوری پروژه لحاظ می‌شود.', $fa['finance.expense.contract_note']);
    } finally {
        $a->locale('en');
    }
};

$tests['CSRF on create and edit with the new fields'] = function () use ($boot, $project, $svc, $input) {
    $a = $boot();
    $p = $project($a);
    $id = (int) $svc($a)->create($a->admin, $input($a))['id'];
    LiteAssert::same('csrf', $a->outcome('POST', '/finance/expenses', [], $input($a, ['_csrf' => 'bad', 'project_id' => (string) $p])));
    LiteAssert::same('csrf', $a->outcome('POST', "/finance/expenses/{$id}", [], $input($a, ['_csrf' => 'bad', 'project_id' => (string) $p])));
};

$tests['rollback leaves no test data behind'] = function () use ($boot) {
    $a = $boot();
    $a->rollBack();
    LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM finance_expenses WHERE description LIKE 'LiteTest S51%'")->fetchColumn());
    LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM contracts WHERE title LIKE 'LiteTest S51%'")->fetchColumn());
    LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM projects WHERE name LIKE 'LiteTest S51%'")->fetchColumn());
    LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM roles WHERE `key` LIKE 'litetest_s51_%'")->fetchColumn());
};

return $tests;
