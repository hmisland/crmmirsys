<?php

declare(strict_types=1);

/**
 * Phase 4 — Expenses (docs/project-finance-design.md: E1–E12, D-P1–D-P3).
 *
 * One positive amount = actual cost (recoverable tax excluded, no tax
 * logic); one optional project (project_id); payee contact OR company;
 * fixed categories; recorded → void (reason), no delete; edits / void /
 * receipts only in open periods; corrections for closed periods with a
 * never-negative net; finance.expenses.view / .manage with record scope;
 * receipts only through Finance routes; generic project timeline; no
 * effect on any revenue / invoice / payment / credit / installment figure.
 *
 * Race conditions (period close, concurrent corrections) are covered by
 * tests/concurrency/expense-probe.php. crm_local only, one transaction, rolled back.
 */

require_once __DIR__ . '/Support/LocalApp.php';
require_once __DIR__ . '/Support/ProjectsLite.php';

use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use Modules\Finance\ExpenseRepository;
use Modules\Finance\Services\ExpenseService;
use Tests\Lite\Support\ProjectsLite;

$projectsBoot = ProjectsLite::booter(true);
$h = new \stdClass();
$h->ready = false;
$boot = function () use ($projectsBoot, $h) {
    $a = $projectsBoot();
    if (!$h->ready) {
        if ($a->pdo->query("SHOW TABLES LIKE 'finance_expenses'")->fetchColumn() === false) {
            throw new LiteSkip('Finance migration 0018 not applied to crm_local');
        }
        $finance = (int) $a->pdo->query("SELECT id FROM modules WHERE `key` = 'finance'")->fetchColumn();
        $a->pdo->prepare("UPDATE modules SET status = 'active' WHERE id = ?")->execute([$finance]);
        $a->pdo->prepare('DELETE FROM organization_modules WHERE organization_id = ? AND module_id = ?')->execute([$a->orgId, $finance]);
        $a->pdo->prepare('INSERT INTO organization_modules (organization_id, module_id, enabled) VALUES (?, ?, 1)')->execute([$a->orgId, $finance]);
        // One closed period far in the past, nothing else closed.
        $a->pdo->prepare("DELETE FROM financial_periods WHERE organization_id = ? AND status = 'closed'")->execute([$a->orgId]);
        $a->pdo->prepare("INSERT INTO financial_periods (organization_id, starts_on, ends_on, status) VALUES (?, '2001-01-01', '2001-12-31', 'closed')")->execute([$a->orgId]);
        $h->closedPeriodId = (int) $a->pdo->lastInsertId();
        $a->as($a->admin);
        $a->locale('en');
        $h->cur = array_map('intval', $a->pdo->query('SELECT id FROM currencies WHERE is_active = 1 ORDER BY id LIMIT 2')->fetchAll(\PDO::FETCH_COLUMN));
        $h->ready = true;
    }

    return $a;
};
$k = fn ($a) => $a->container();
$engine = fn ($a) => $k($a)->make(\App\Infrastructure\Authorization\AuthorizationEngine::class);
/** Built with the same arguments FinanceModule passes (the route tests cover the real wiring). */
$svc = fn ($a): ExpenseService => new ExpenseService(new ExpenseRepository(), $engine($a), $k($a)->make(\App\Application\Services\TimelineService::class),
    $k($a)->make(\App\Application\Services\AttachmentService::class), $k($a)->make(\App\Infrastructure\Records\RecordTypeRegistry::class));
$input = fn ($a, array $extra = []) => $extra + [
    'category' => 'procurement', 'description' => 'LiteTest S4 expense', 'reference_number' => 'R-1',
    'expense_date' => date('Y-m-d'), 'amount' => '100', 'currency_id' => (string) (int) $a->pdo->query('SELECT id FROM currencies WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn(),
];
$add = fn ($a, array $extra = [], ?array $user = null): int => (int) $svc($a)->create($user ?? $a->admin, $input($a, $extra))['id'];
/** An expense dated in the CLOSED 2001 period (created open, then moved there directly). */
$closedExpense = function ($a, array $extra = [], ?array $user = null) use ($add): int {
    $id = $add($a, $extra, $user);
    $a->pdo->exec("UPDATE finance_expenses SET expense_date = '2001-06-01' WHERE id = {$id}");

    return $id;
};
$project = function ($a, string $status = 'active', ?array $owner = null): int {
    $a->as($a->admin);
    $id = (int) ProjectsLite::service($a)->create($a->admin, ['name' => 'LiteTest S4 project ' . bin2hex(random_bytes(2))])['id'];
    $a->pdo->prepare('UPDATE projects SET status = ?, owner_id = ? WHERE id = ?')->execute([$status, (int) ($owner ?? $a->admin)['id'], $id]);

    return $id;
};
$userWith = function ($a, array $permissions): array {
    $a->pdo->prepare('INSERT INTO roles (organization_id, `key`, name, is_system) VALUES (?, ?, ?, 0)')
        ->execute([$a->orgId, 'litetest_s4_' . bin2hex(random_bytes(4)), 'LiteTest S4']);
    $roleId = (int) $a->pdo->lastInsertId();
    foreach ($permissions as $key => $scope) {
        $a->pdo->prepare('INSERT INTO role_permissions (role_id, permission_key, scope) VALUES (?, ?, ?)')->execute([$roleId, $key, $scope]);
    }

    return array_merge($a->member, ['role_id' => $roleId]);
};
$full = ['finance.expenses.view' => 'organization', 'finance.expenses.manage' => 'organization', 'projects.view' => 'organization', 'projects.edit' => 'organization'];
$as = function ($a, array $user, callable $fn) {
    $a->as($user);
    try {
        return $fn();
    } finally {
        $a->as($a->admin);
    }
};
$row = fn ($a, int $id): array => $a->pdo->query("SELECT * FROM finance_expenses WHERE id = {$id}")->fetch(\PDO::FETCH_ASSOC);
$count = fn ($a): int => (int) $a->pdo->query('SELECT COUNT(*) FROM finance_expenses')->fetchColumn();
$events = fn ($a, string $subject, int $id): array => $a->pdo->query("SELECT type, description FROM timeline_events WHERE subject_type = '{$subject}' AND subject_id = {$id} ORDER BY id")->fetchAll(\PDO::FETCH_ASSOC);
$refused = function (callable $fn, string $field, ?string $key = null): void {
    try {
        $fn();
    } catch (ValidationException $e) {
        LiteAssert::true(isset($e->errors[$field]), "expected {$field}, got " . json_encode($e->errors, JSON_UNESCAPED_UNICODE));
        if ($key !== null) {
            $en = require dirname(__DIR__, 2) . '/lang/en.php';
            LiteAssert::same($en[$key], $e->errors[$field][0] ?? null);
        }

        return;
    }
    throw new LiteAssertionFailed("accepted (expected {$field})");
};
$panel = function ($a, int $projectId, ?array $user = null) use ($svc, $engine) {
    $user ??= $a->admin;

    return $svc($a)->projectPanel($user, $svc($a)->viewableProject($user, $projectId), fn (string $key): bool => $engine($a)->can($user, $key));
};
$en = require dirname(__DIR__, 2) . '/lang/en.php';

$tests = [];

$tests['1. create through the route: one recorded row with owner / team / department, expense timeline; nothing financial elsewhere'] = function () use ($boot, $input, $row, $events) {
    $a = $boot();
    $before = $a->pdo->query("SELECT (SELECT COUNT(*) FROM invoices), (SELECT COUNT(*) FROM payments), (SELECT COUNT(*) FROM credit_notes), (SELECT COUNT(*) FROM finance_document_links), (SELECT COUNT(*) FROM finance_installments)")->fetch(\PDO::FETCH_NUM);
    $r = $a->request('POST', '/finance/expenses', [], $input($a, ['amount' => '1,234.5']));
    LiteAssert::same(302, $r['status']);
    $id = (int) basename((string) $r['location']);
    $x = $row($a, $id);
    LiteAssert::same(['expense', 'recorded', 'procurement', (int) $a->admin['id']], [$x['entry_type'], $x['status'], $x['category'], (int) $x['owner_id']]);
    LiteAssert::same(0, bccomp('1234.5', (string) $x['amount'], 18), 'amount stored as entered (no tax logic)');
    LiteAssert::same(null, $x['project_id']);
    LiteAssert::same('created', $events($a, 'expense', $id)[0]['type'] ?? null);
    LiteAssert::same($before, $a->pdo->query("SELECT (SELECT COUNT(*) FROM invoices), (SELECT COUNT(*) FROM payments), (SELECT COUNT(*) FROM credit_notes), (SELECT COUNT(*) FROM finance_document_links), (SELECT COUNT(*) FROM finance_installments)")->fetch(\PDO::FETCH_NUM), 'no invoice / payment / credit / link / installment written');
};

$tests['2. validation (E3–E5): category, description, reference, date, amount > 0, active currency, one payee of this organization'] = function () use ($boot, $svc, $input, $refused, $count) {
    $a = $boot();
    $before = $count($a);
    $create = fn (array $extra) => fn () => $svc($a)->create($a->admin, $input($a, $extra));
    $refused($create(['category' => 'travel']), 'category');
    $refused($create(['description' => '']), 'description');
    $refused($create(['description' => str_repeat('x', 256)]), 'description');
    $refused($create(['reference_number' => str_repeat('x', 101)]), 'reference_number');
    foreach (['', '2026-02-30', '01/02/2026'] as $bad) {
        $refused($create(['expense_date' => $bad]), 'expense_date');
    }
    foreach (['0', '-5', 'abc', ''] as $bad) {
        $refused($create(['amount' => $bad]), 'amount', 'finance.expense.error.invalid_amount');
    }
    $inactive = (int) $a->pdo->query('SELECT id FROM currencies WHERE is_active = 0 ORDER BY id LIMIT 1')->fetchColumn();
    if ($inactive > 0) {
        $refused($create(['currency_id' => (string) $inactive]), 'currency_id');
    }
    $refused($create(['currency_id' => '999999']), 'currency_id');
    $contact = ProjectsLite::contactId($a);
    $company = ProjectsLite::companyId($a);
    if ($contact > 0 && $company > 0) {
        $refused($create(['payee_contact_id' => (string) $contact, 'payee_company_id' => (string) $company]), 'payee_contact_id', 'finance.expense.error.one_payee');
    }
    $refused($create(['payee_contact_id' => '999999999']), 'payee_contact_id');
    $refused($create(['payee_company_id' => '999999999']), 'payee_company_id');
    LiteAssert::same($before, $count($a), 'nothing written');
    if ($contact > 0) {
        $svc($a)->create($a->admin, $input($a, ['payee_contact_id' => (string) $contact]));
    }
    if ($company > 0) {
        $svc($a)->create($a->admin, $input($a, ['payee_company_id' => (string) $company]));
    }
};

$tests['3. E3: one amount, no tax calculation (TaxCalculationService not used)'] = function () use ($boot) {
    $a = $boot();
    $src = (string) file_get_contents(dirname(__DIR__, 2) . '/modules/Finance/Services/ExpenseService.php');
    LiteAssert::false(str_contains($src, 'TaxCalculationService'), 'no tax engine');
    $cols = $a->pdo->query('SHOW COLUMNS FROM finance_expenses')->fetchAll(\PDO::FETCH_COLUMN);
    LiteAssert::same([], array_values(array_filter($cols, fn ($c) => str_contains($c, 'tax'))), 'no tax column');
};

$tests['4. permissions: keys registered; default member nothing; view-only can read not write; own scope sees / manages only own'] = function () use ($boot, $add, $userWith, $as, $input, $svc) {
    $a = $boot();
    LiteAssert::true(in_array('finance.expenses.view', \App\Infrastructure\Authorization\Permission::all(), true) && in_array('finance.expenses.manage', \App\Infrastructure\Authorization\Permission::all(), true));
    $mine = $add($a);
    $member = $a->member; // system member role: no expense keys
    LiteAssert::same('forbidden', $as($a, $member, fn () => $a->outcome('GET', '/finance/expenses')));
    LiteAssert::same('forbidden', $as($a, $member, fn () => $a->outcome('POST', '/finance/expenses', [], $input($a))));
    $viewer = $userWith($a, ['finance.expenses.view' => 'organization']);
    LiteAssert::same('ok', $as($a, $viewer, fn () => $a->outcome('GET', "/finance/expenses/{$mine}")));
    LiteAssert::same('forbidden', $as($a, $viewer, fn () => $a->outcome('POST', '/finance/expenses', [], $input($a))));
    LiteAssert::same('forbidden', $as($a, $viewer, fn () => $a->outcome('POST', "/finance/expenses/{$mine}/void", [], ['reason' => 'x'])));
    $own = $userWith($a, ['finance.expenses.view' => 'own', 'finance.expenses.manage' => 'own']);
    $theirs = $add($a, [], $own);
    LiteAssert::same('forbidden', $as($a, $own, fn () => $a->outcome('GET', "/finance/expenses/{$mine}")), 'not their expense');
    LiteAssert::throws(AuthorizationException::class, fn () => $svc($a)->update($own, $mine, $input($a)));
    LiteAssert::throws(AuthorizationException::class, fn () => $svc($a)->void($own, $mine, 'not theirs'));
    LiteAssert::same('recorded', $a->pdo->query("SELECT status FROM finance_expenses WHERE id = {$mine}")->fetchColumn());
    $ids = array_map(fn ($r) => (int) $r['id'], $svc($a)->listPage($own, [], 500, 0)['rows']);
    LiteAssert::true(in_array($theirs, $ids, true) && !in_array($mine, $ids, true), 'list restricted to own');
    $svc($a)->update($own, $theirs, $input($a, ['description' => 'edited by owner']));
};

$tests['5. tenant isolation: another organization\'s expense → 404 on every route; never listed'] = function () use ($boot, $add, $svc) {
    $a = $boot();
    if ($a->otherOrgId === null) {
        throw new LiteSkip('only one organization');
    }
    $x = $add($a);
    $a->pdo->exec("UPDATE finance_expenses SET organization_id = {$a->otherOrgId} WHERE id = {$x}");
    foreach ([['GET', "/finance/expenses/{$x}", []], ['GET', "/finance/expenses/{$x}/edit", []], ['POST', "/finance/expenses/{$x}", ['amount' => '1']], ['POST', "/finance/expenses/{$x}/void", ['reason' => 'x']],
        ['POST', "/finance/expenses/{$x}/corrections", ['amount' => '1']], ['POST', "/finance/expenses/{$x}/attachments", []], ['GET', "/finance/expenses/{$x}/attachments/1", []]] as [$m, $p, $b]) {
        LiteAssert::same('notfound', $a->outcome($m, $p, [], $b), "{$m} {$p}");
    }
    LiteAssert::false(in_array($x, array_map(fn ($r) => (int) $r['id'], $svc($a)->listPage($a->admin, [], 500, 0)['rows']), true));
};

$tests['6. D-P1 / E7 / E9: new expense from a project — prefill, both permissions, cancelled refused, completed allowed, hidden field re-checked'] = function () use ($boot, $project, $input, $userWith, $full, $as, $row, $events, $count) {
    $a = $boot();
    $p = $project($a);
    $form = $a->request('GET', '/finance/expenses/create', ['from_project' => (string) $p]);
    LiteAssert::same(200, $form['status']);
    LiteAssert::contains('name="project_id" value="' . $p . '"', $form['body']);
    $r = $a->request('POST', '/finance/expenses', [], $input($a, ['project_id' => (string) $p, 'amount' => '777']));
    $id = (int) basename((string) $r['location']);
    LiteAssert::same($p, (int) $row($a, $id)['project_id']);
    $pe = $events($a, 'project', $p);
    LiteAssert::same('An expense was attributed to this project', end($pe)['description']);
    LiteAssert::notContains('777', json_encode($pe), 'no amount on the project timeline');

    $before = $count($a);
    $noProjectEdit = $userWith($a, array_diff_key($full, ['projects.edit' => 1]));
    LiteAssert::same('forbidden', $as($a, $noProjectEdit, fn () => $a->outcome('GET', '/finance/expenses/create', ['from_project' => (string) $p])));
    LiteAssert::same('forbidden', $as($a, $noProjectEdit, fn () => $a->outcome('POST', '/finance/expenses', [], $input($a, ['project_id' => (string) $p]))), 'hidden project_id re-checked');
    $blind = $userWith($a, array_diff_key($full, ['projects.view' => 1]));
    LiteAssert::same('notfound', $as($a, $blind, fn () => $a->outcome('GET', '/finance/expenses/create', ['from_project' => (string) $p])));
    LiteAssert::same(422, $as($a, $blind, fn () => $a->request('POST', '/finance/expenses', [], $input($a, ['project_id' => (string) $p]))['status']));
    $cancelled = $project($a, 'cancelled');
    LiteAssert::same([302, "/projects/{$cancelled}"], (function () use ($a, $cancelled) { $r = $a->request('GET', '/finance/expenses/create', ['from_project' => (string) $cancelled]); return [$r['status'], $r['location']]; })());
    LiteAssert::same(422, $a->request('POST', '/finance/expenses', [], $input($a, ['project_id' => (string) $cancelled]))['status']);
    if ($a->otherOrgId !== null) {
        LiteAssert::same(422, $a->request('POST', '/finance/expenses', [], $input($a, ['project_id' => (string) ProjectsLite::foreignProject($a)]))['status'], 'foreign project');
    }
    LiteAssert::same($before, $count($a), 'nothing written by refused requests');
    $completed = $project($a, 'completed');
    LiteAssert::same(302, $a->request('POST', '/finance/expenses', [], $input($a, ['project_id' => (string) $completed]))['status'], 'completed accepted (late costs)');
};

$tests['7. E7 on edit: removing / changing the project needs projects.edit on the old AND the new project'] = function () use ($boot, $project, $add, $svc, $userWith, $full, $row, $refused) {
    $a = $boot();
    $p1 = $project($a);
    $p2 = $project($a);
    $x = $add($a, ['project_id' => (string) $p1]);
    $ownProjects = $userWith($a, ['projects.edit' => 'own'] + $full);
    LiteAssert::throws(AuthorizationException::class, fn () => $svc($a)->update($ownProjects, $x, ['remove_project' => '1'] + $svc($a)->find($a->admin, $x)), 'remove without rights on the old project');
    $theirs = $project($a, 'active', $ownProjects);
    LiteAssert::throws(AuthorizationException::class, fn () => $svc($a)->update($ownProjects, $x, ['project_id' => (string) $theirs] + $svc($a)->find($a->admin, $x)), 'change needs the old project too');
    $svc($a)->update($a->admin, $x, ['project_id' => (string) $p2] + $svc($a)->find($a->admin, $x));
    LiteAssert::same($p2, (int) $row($a, $x)['project_id']);
    $cancelled = $project($a, 'cancelled');
    $refused(fn () => $svc($a)->update($a->admin, $x, ['project_id' => (string) $cancelled] + $svc($a)->find($a->admin, $x)), 'project_id', 'finance.expense.error.project_cancelled');
    $svc($a)->update($a->admin, $x, ['remove_project' => '1'] + $svc($a)->find($a->admin, $x));
    LiteAssert::same(null, $row($a, $x)['project_id']);
};

$tests['8. E9: later project status changes or deletion never touch the expense'] = function () use ($boot, $project, $add, $row) {
    $a = $boot();
    $p = $project($a);
    $x = $add($a, ['project_id' => (string) $p]);
    $a->pdo->exec("UPDATE projects SET status = 'cancelled' WHERE id = {$p}");
    LiteAssert::same([$p, 'recorded'], [(int) $row($a, $x)['project_id'], $row($a, $x)['status']]);
    $a->pdo->exec("DELETE FROM projects WHERE id = {$p}");
    LiteAssert::same($p, (int) $row($a, $x)['project_id'], 'kept after deletion');
    LiteAssert::same(200, $a->request('GET', "/finance/expenses/{$x}")['status']);
};

$tests['9. closed periods (D-P3 checks): create, edit (old or new date), void and receipts refused'] = function () use ($boot, $svc, $input, $add, $closedExpense, $refused, $count) {
    $a = $boot();
    $before = $count($a);
    $refused(fn () => $svc($a)->create($a->admin, $input($a, ['expense_date' => '2001-03-03'])), 'expense_date', 'finance.expense.error.period_closed');
    LiteAssert::same($before, $count($a));
    $open = $add($a);
    $refused(fn () => $svc($a)->update($a->admin, $open, $input($a, ['expense_date' => '2001-03-03'])), 'expense_date');
    $closed = $closedExpense($a);
    $refused(fn () => $svc($a)->update($a->admin, $closed, $input($a)), 'expense_date');
    $refused(fn () => $svc($a)->void($a->admin, $closed, 'x'), 'expense_date');
    $refused(fn () => $svc($a)->uploadReceipt($a->admin, $closed, ['name' => 'r.pdf', 'tmp_name' => __FILE__, 'error' => UPLOAD_ERR_OK, 'size' => 10]), 'expense_date');
    $refused(fn () => $svc($a)->deleteReceipt($a->admin, $closed, 1), 'expense_date');
};

$tests['10. void: reason required (≤ 450), status void, excluded from project totals, no second void, no edit after'] = function () use ($boot, $project, $add, $svc, $refused, $row, $panel, $input) {
    $a = $boot();
    $p = $project($a);
    $x = $add($a, ['project_id' => (string) $p, 'amount' => '50']);
    $refused(fn () => $svc($a)->void($a->admin, $x, '  '), 'reason', 'finance.expense.error.reason_required');
    $refused(fn () => $svc($a)->void($a->admin, $x, str_repeat('x', 451)), 'reason', 'finance.expense.error.reason_too_long');
    $svc($a)->void($a->admin, $x, ' Duplicate ');
    $r = $row($a, $x);
    LiteAssert::same(['void', 'Duplicate', (int) $a->admin['id']], [$r['status'], $r['void_reason'], (int) $r['voided_by_user_id']]);
    LiteAssert::same([], $panel($a, $p)['totals'], 'excluded');
    $refused(fn () => $svc($a)->void($a->admin, $x, 'again'), 'expense', 'finance.expense.error.not_recorded');
    $refused(fn () => $svc($a)->update($a->admin, $x, $input($a)), 'expense', 'finance.expense.error.not_recorded');
    LiteAssert::same(1, (int) $a->pdo->query("SELECT COUNT(*) FROM finance_expenses WHERE id = {$x}")->fetchColumn(), 'never deleted');
};

$tests['11. E8 corrections: only for a closed original, own date open, copies currency / project / category / scope, net never negative, no chains'] = function () use ($boot, $h, $project, $add, $closedExpense, $svc, $refused, $row, $input) {
    $a = $boot();
    $p = $project($a);
    $open = $add($a);
    $refused(fn () => $svc($a)->createCorrection($a->admin, $open, ['correction_direction' => 'decrease'] + $input($a, ['amount' => '1'])), 'expense', 'finance.expense.error.original_period_open');
    $orig = $closedExpense($a, ['project_id' => (string) $p, 'amount' => '100', 'category' => 'labor']);
    $refused(fn () => $svc($a)->createCorrection($a->admin, $orig, ['correction_direction' => 'sideways'] + $input($a)), 'correction_direction');
    $refused(fn () => $svc($a)->createCorrection($a->admin, $orig, ['correction_direction' => 'decrease'] + $input($a, ['expense_date' => '2001-08-08'])), 'expense_date', 'finance.expense.error.period_closed');
    $inc = (int) $svc($a)->createCorrection($a->admin, $orig, ['correction_direction' => 'increase'] + $input($a, ['amount' => '20', 'currency_id' => (string) ($h->cur[1] ?? $h->cur[0]), 'category' => 'other']))['id'];
    $c = $row($a, $inc);
    $o = $row($a, $orig);
    LiteAssert::same([$o['currency_id'], $o['project_id'], 'labor', $o['owner_id'], 'correction', $orig], [$c['currency_id'], $c['project_id'], $c['category'], $c['owner_id'], $c['entry_type'], (int) $c['correction_of_id']], 'copied from the original, not from the form');
    $dec = (int) $svc($a)->createCorrection($a->admin, $orig, ['correction_direction' => 'decrease'] + $input($a, ['amount' => '120']))['id']; // 100 + 20 − 120 = 0
    $refused(fn () => $svc($a)->createCorrection($a->admin, $orig, ['correction_direction' => 'decrease'] + $input($a, ['amount' => '0.01'])), 'amount', 'finance.expense.error.negative_net');
    $refused(fn () => $svc($a)->createCorrection($a->admin, $inc, ['correction_direction' => 'increase'] + $input($a)), 'expense', 'finance.expense.error.not_an_original');
    $refused(fn () => $svc($a)->update($a->admin, $inc, $input($a)), 'expense', 'finance.expense.error.correction_not_editable');
    $refused(fn () => $svc($a)->void($a->admin, $inc, 'would make net −20'), 'amount', 'finance.expense.error.negative_net');
    $svc($a)->void($a->admin, $dec, 'too much');
    $svc($a)->void($a->admin, $inc, 'now fine: 100');
    LiteAssert::same(0, bccomp('100', $svc($a)->net($row($a, $orig), (new ExpenseRepository())->correctionsOf($orig)), 18));
    $original = $row($a, $orig);
    LiteAssert::same(['recorded', '2001-06-01'], [$original['status'], $original['expense_date']], 'the original is never modified by corrections');
};

$tests['12. original with recorded corrections: void refused; when its period reopens, currency / project / category frozen and the net stays ≥ 0'] = function () use ($boot, $h, $closedExpense, $svc, $refused, $input) {
    $a = $boot();
    $orig = $closedExpense($a, ['amount' => '100']);
    $svc($a)->createCorrection($a->admin, $orig, ['correction_direction' => 'decrease'] + $input($a, ['amount' => '60']));
    $a->pdo->exec("UPDATE financial_periods SET status = 'open' WHERE organization_id = {$a->orgId} AND starts_on = '2001-01-01'");
    try {
        $refused(fn () => $svc($a)->void($a->admin, $orig, 'x'), 'expense', 'finance.expense.error.has_corrections');
        $refused(fn () => $svc($a)->update($a->admin, $orig, $input($a, ['expense_date' => '2001-06-01', 'amount' => '50'])), 'amount', 'finance.expense.error.negative_net');
        $refused(fn () => $svc($a)->update($a->admin, $orig, $input($a, ['expense_date' => '2001-06-01', 'category' => 'other'])), 'expense', 'finance.expense.error.frozen_by_corrections');
        $svc($a)->update($a->admin, $orig, $input($a, ['expense_date' => '2001-06-01', 'amount' => '60', 'category' => 'procurement']));
    } finally {
        $a->pdo->exec("UPDATE financial_periods SET status = 'closed' WHERE organization_id = {$a->orgId} AND starts_on = '2001-01-01'");
    }
};

$tests['13. D-P2: correction rights — manage on the ORIGINAL, projects.edit when it has a project; deleted project needs no project right; cancelled project still correctable'] = function () use ($boot, $project, $closedExpense, $svc, $userWith, $full, $input) {
    $a = $boot();
    $p = $project($a);
    $orig = $closedExpense($a, ['project_id' => (string) $p]);
    $ownManager = $userWith($a, ['finance.expenses.view' => 'organization', 'finance.expenses.manage' => 'own'] + $full);
    LiteAssert::throws(AuthorizationException::class, fn () => $svc($a)->createCorrection($ownManager, $orig, ['correction_direction' => 'increase'] + $input($a)), 'manage own, not their original');
    $noProject = $userWith($a, array_diff_key($full, ['projects.edit' => 1]));
    LiteAssert::throws(AuthorizationException::class, fn () => $svc($a)->createCorrection($noProject, $orig, ['correction_direction' => 'increase'] + $input($a)), 'project right needed');
    $a->pdo->exec("UPDATE projects SET status = 'cancelled' WHERE id = {$p}");
    $svc($a)->createCorrection($a->admin, $orig, ['correction_direction' => 'increase'] + $input($a, ['amount' => '5'])); // cancelled: still correctable
    $a->pdo->exec("DELETE FROM projects WHERE id = {$p}");
    $svc($a)->createCorrection($noProject, $orig, ['correction_direction' => 'increase'] + $input($a, ['amount' => '5'])); // gone: expense right only
};

$tests['14. project costs: net of each original exactly once, per currency, corrections included, void excluded, restricted not summed'] = function () use ($boot, $h, $project, $add, $closedExpense, $svc, $panel, $userWith, $input) {
    $a = $boot();
    $p = $project($a);
    $orig = $closedExpense($a, ['project_id' => (string) $p, 'amount' => '100']);
    $svc($a)->createCorrection($a->admin, $orig, ['correction_direction' => 'increase'] + $input($a, ['amount' => '20']));
    $svc($a)->createCorrection($a->admin, $orig, ['correction_direction' => 'decrease'] + $input($a, ['amount' => '50']));
    $voided = (int) $svc($a)->createCorrection($a->admin, $orig, ['correction_direction' => 'increase'] + $input($a, ['amount' => '999']))['id'];
    $svc($a)->void($a->admin, $voided, 'mistake');
    $add($a, ['project_id' => (string) $p, 'amount' => '30']);
    $second = count($h->cur) > 1 ? $h->cur[1] : null;
    if ($second !== null) {
        $add($a, ['project_id' => (string) $p, 'amount' => '7', 'currency_id' => (string) $second]);
    }
    $totals = $panel($a, $p)['totals'];
    LiteAssert::same(0, bccomp('100', $totals[$h->cur[0]], 18), '100 + 20 − 50 + 30 (void excluded)');
    if ($second !== null) {
        LiteAssert::same(0, bccomp('7', $totals[$second], 18), 'separate currency');
        LiteAssert::same(2, count($totals));
    }
    $own = $userWith($a, ['finance.expenses.view' => 'own', 'projects.view' => 'organization']);
    $add($a, ['project_id' => (string) $p, 'amount' => '3'], $userWith($a, ['finance.expenses.view' => 'own', 'finance.expenses.manage' => 'own', 'projects.view' => 'organization', 'projects.edit' => 'organization']));
    // Both temporary users are the same member account: they see only that member's 3, never the admin's 100.
    LiteAssert::same([$h->cur[0] => 0], array_map(fn ($v) => bccomp('3', $v, 18), $panel($a, $p, $own)['totals']), 'restricted rows not summed');
};

$tests['15. receipts (E6): Core record routes 404; download needs view + scope; IDOR on attachment id; upload / delete need manage, recorded, open period'] = function () use ($boot, $add, $svc, $userWith, $as, $refused) {
    $a = $boot();
    $x = $add($a);
    $y = $add($a);
    LiteAssert::same('notfound', $a->outcome('POST', "/records/expense/{$x}/attachments", [], []), 'Core RecordSupport off for expenses');
    LiteAssert::same('notfound', $a->outcome('GET', "/records/expense/{$x}/attachments/1"));
    $dir = dirname(__DIR__, 2) . '/storage/uploads/' . $a->orgId;
    @mkdir($dir, 0777, true);
    $name = 'litetest-s4-' . bin2hex(random_bytes(4)) . '.txt';
    file_put_contents($dir . '/' . $name, 'receipt-body');
    try {
        $a->pdo->prepare("INSERT INTO attachments (organization_id, attachable_type, attachable_id, user_id, filename, stored_path, mime_type, size) VALUES (?, 'expense', ?, ?, 'r.txt', ?, 'text/plain', 12)")
            ->execute([$a->orgId, $x, (int) $a->admin['id'], $a->orgId . '/' . $name]);
        $att = (int) $a->pdo->lastInsertId();
        $r = $a->request('GET', "/finance/expenses/{$x}/attachments/{$att}");
        LiteAssert::same([200, 'receipt-body'], [$r['status'], $r['body']]);
        LiteAssert::same('notfound', $a->outcome('GET', "/finance/expenses/{$y}/attachments/{$att}"), 'attachment of another expense');
        LiteAssert::same('notfound', $a->outcome('POST', "/finance/expenses/{$y}/attachments/{$att}/delete"));
        $own = $userWith($a, ['finance.expenses.view' => 'own', 'finance.expenses.manage' => 'own']);
        LiteAssert::same('forbidden', $as($a, $own, fn () => $a->outcome('GET', "/finance/expenses/{$x}/attachments/{$att}")));
        LiteAssert::same('forbidden', $as($a, $own, fn () => $a->outcome('POST', "/finance/expenses/{$x}/attachments/{$att}/delete")));
        $viewer = $userWith($a, ['finance.expenses.view' => 'organization']);
        LiteAssert::same('forbidden', $as($a, $viewer, fn () => $a->outcome('POST', "/finance/expenses/{$x}/attachments/{$att}/delete")));
        // Upload: every Finance check passes, then AttachmentService refuses a non-uploaded test file.
        $refused(fn () => $svc($a)->uploadReceipt($a->admin, $x, ['name' => 'r.txt', 'tmp_name' => $dir . '/' . $name, 'error' => UPLOAD_ERR_OK, 'size' => 12]), 'file');
        $refused(fn () => $svc($a)->uploadReceipt($a->admin, $x, null), 'file', 'finance.expense.error.file_required');
        $svc($a)->void($a->admin, $x, 'void it');
        $refused(fn () => $svc($a)->deleteReceipt($a->admin, $x, $att), 'file', 'finance.expense.error.not_recorded');
        $svc($a)->deleteReceipt($a->admin, $y, 999999999) ?? null;
    } catch (NotFoundException) {
        // the last call: an unknown attachment of $y → 404, as intended
    } finally {
        @unlink($dir . '/' . $name);
    }
    $z = $add($a);
    $a->pdo->prepare("INSERT INTO attachments (organization_id, attachable_type, attachable_id, user_id, filename, stored_path, mime_type, size) VALUES (?, 'expense', ?, ?, 'z.txt', 'nowhere/none.txt', 'text/plain', 1)")->execute([$a->orgId, $z, (int) $a->admin['id']]);
    $zAtt = (int) $a->pdo->lastInsertId();
    $svc($a)->deleteReceipt($a->admin, $z, $zAtt);
    LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM attachments WHERE id = {$zAtt}")->fetchColumn(), 'deleted while recorded + open');
};

$tests['16. CSRF on every expense POST'] = function () use ($boot, $add, $input) {
    $a = $boot();
    $x = $add($a);
    foreach (['/finance/expenses', "/finance/expenses/{$x}", "/finance/expenses/{$x}/void", "/finance/expenses/{$x}/corrections", "/finance/expenses/{$x}/attachments", "/finance/expenses/{$x}/attachments/1/delete"] as $path) {
        LiteAssert::same('csrf', $a->outcome('POST', $path, [], $input($a, ['_csrf' => 'bad', 'reason' => 'x'])), $path);
    }
};

$tests['17. timeline: details on the expense, generic text on the project (no amount, currency or receipt detail)'] = function () use ($boot, $project, $add, $closedExpense, $svc, $events, $input) {
    $a = $boot();
    $p = $project($a);
    $x = $add($a, ['project_id' => (string) $p, 'amount' => '4321']);
    $svc($a)->void($a->admin, $x, 'secret reason');
    $orig = $closedExpense($a, ['project_id' => (string) $p, 'amount' => '8765']);
    $svc($a)->createCorrection($a->admin, $orig, ['correction_direction' => 'increase'] + $input($a, ['amount' => '1111']));
    $projectText = json_encode($events($a, 'project', $p));
    foreach (['4321', '8765', '1111', 'secret reason'] as $secret) {
        LiteAssert::notContains($secret, $projectText, "project timeline: {$secret}");
    }
    LiteAssert::contains('4321', json_encode($events($a, 'expense', $x)), 'expense timeline has the amount');
    LiteAssert::contains('secret reason', json_encode($events($a, 'expense', $x)));
};

$tests['18. no effect on revenue: Contract Billing, Project Revenue, invoices, payments, credit notes, installments unchanged'] = function () use ($boot, $h, $project, $add, $closedExpense, $svc, $input, $engine, $k) {
    $a = $boot();
    $p = $project($a);
    $can = fn (string $key): bool => $engine($a)->can($a->admin, $key);
    $revenue = new \Modules\Finance\Services\ProjectRevenueService(new \Modules\Finance\ProjectRevenueRepository(), new \Modules\Finance\FinanceDocumentLinkRepository(), new \Modules\Finance\InvoiceRepository(),
        $engine($a), $k($a)->make(\App\Application\Services\TimelineService::class), $k($a)->make(\App\Infrastructure\Records\RecordTypeRegistry::class));
    $snap = fn () => [
        $revenue->panelFor($a->admin, $revenue->project($a->admin, $p), $can),
        $a->pdo->query('SELECT id, status, grand_total, paid_total FROM invoices ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC),
        $a->pdo->query('SELECT COUNT(*), COALESCE(SUM(amount), 0) FROM payments')->fetch(\PDO::FETCH_NUM),
        $a->pdo->query('SELECT COUNT(*), COALESCE(SUM(amount), 0) FROM credit_notes')->fetch(\PDO::FETCH_NUM),
        $a->pdo->query('SELECT * FROM finance_document_links ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC),
        $a->pdo->query('SELECT * FROM finance_installments ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC),
    ];
    $before = $snap();
    $x = $add($a, ['project_id' => (string) $p, 'amount' => '500']);
    $svc($a)->void($a->admin, $x, 'x');
    $orig = $closedExpense($a, ['project_id' => (string) $p]);
    $svc($a)->createCorrection($a->admin, $orig, ['correction_direction' => 'increase'] + $input($a));
    LiteAssert::same($before, $snap());
};

$tests['19. UI: Finance nav item, pages and the Project Costs panel render; panel only with finance.expenses.view; 5 languages, no raw keys'] = function () use ($boot, $project, $add, $userWith, $as) {
    $a = $boot();
    $p = $project($a);
    $x = $add($a, ['project_id' => (string) $p]);
    LiteAssert::contains('href="/finance/expenses"', $a->request('GET', '/finance/expenses')['body'], 'nav item');
    $noExpenses = $userWith($a, ['projects.view' => 'organization']);
    LiteAssert::notContains('data-related-panel="finance.project_costs"', $as($a, $noExpenses, fn () => $a->request('GET', "/projects/{$p}")['body']));
    try {
        foreach (['en', 'fa', 'ar', 'tr', 'fr'] as $locale) {
            $a->locale($locale);
            $lang = require dirname(__DIR__, 2) . "/lang/{$locale}.php";
            foreach (['/finance/expenses', '/finance/expenses/create', "/finance/expenses/{$x}", "/finance/expenses/{$x}/edit", "/projects/{$p}"] as $path) {
                $r = $a->request('GET', $path);
                LiteAssert::same(200, $r['status'], "{$locale} {$path}");
                LiteAssert::notContains('finance.expense.', $r['body'], "{$locale} {$path}: raw key");
            }
            LiteAssert::contains(htmlspecialchars($lang['finance.expense.project_title'], ENT_QUOTES), $a->request('GET', "/projects/{$p}")['body'], $locale);
        }
    } finally {
        $a->locale('en');
    }
};

$tests['20. show page offers only allowed actions (open: edit / void / receipts; closed original: correction; correction: void under D-P2)'] = function () use ($boot, $add, $closedExpense, $svc, $input, $userWith, $as) {
    $a = $boot();
    $open = $a->request('GET', '/finance/expenses/' . $add($a))['body'];
    LiteAssert::contains('/edit"', $open);
    LiteAssert::contains('/void"', $open);
    LiteAssert::notContains('/corrections"', $open);
    $orig = $closedExpense($a);
    $closed = $a->request('GET', "/finance/expenses/{$orig}")['body'];
    LiteAssert::contains('/corrections"', $closed);
    LiteAssert::notContains("/finance/expenses/{$orig}/edit", $closed);
    LiteAssert::notContains("/finance/expenses/{$orig}/void", $closed);
    LiteAssert::notContains('name="file"', $closed, 'no receipt upload in a closed period');
    $viewer = $userWith($a, ['finance.expenses.view' => 'organization']);
    $ro = $as($a, $viewer, fn () => $a->request('GET', "/finance/expenses/{$orig}")['body']);
    LiteAssert::notContains('/corrections"', $ro);
};

$tests['rollback leaves no test data behind'] = function () use ($boot) {
    $a = $boot();
    $a->rollBack();
    LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM finance_expenses WHERE description LIKE 'LiteTest S4%'")->fetchColumn());
    LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM projects WHERE name LIKE 'LiteTest S4%'")->fetchColumn());
    LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM roles WHERE `key` LIKE 'litetest_s4_%'")->fetchColumn());
    LiteAssert::same(0, count(glob(dirname(__DIR__, 2) . '/storage/uploads/*/litetest-s4-*') ?: []));
};

return $tests;
