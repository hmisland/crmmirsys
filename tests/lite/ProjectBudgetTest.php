<?php

declare(strict_types=1);

/**
 * Stage 5 — project expense budget (docs/project-finance-design.md).
 *
 * Lines per category × currency, amount ≥ 0; saving replaces the whole budget
 * (one transaction, the project's lines locked FOR UPDATE); finance.budgets.view
 * (record scope on the project) to see, + finance.budgets.manage + projects.edit
 * to edit, only in draft / planned / active / on_hold; Budget vs Actual per
 * currency × category with "—" where no line exists; generic project timeline.
 *
 * Concurrent saves are covered by tests/concurrency/budget-probe.php.
 * crm_local only, one transaction, rolled back.
 */

require_once __DIR__ . '/Support/LocalApp.php';
require_once __DIR__ . '/Support/ProjectsLite.php';

use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use Modules\Finance\BudgetRepository;
use Modules\Finance\ExpenseRepository;
use Modules\Finance\Services\BudgetService;
use Modules\Finance\Services\ExpenseService;
use Tests\Lite\Support\ProjectsLite;

$projectsBoot = ProjectsLite::booter(true);
$h = new \stdClass();
$h->ready = false;
$boot = function () use ($projectsBoot, $h) {
    $a = $projectsBoot();
    if (!$h->ready) {
        if ($a->pdo->query("SHOW TABLES LIKE 'finance_budget_lines'")->fetchColumn() === false) {
            throw new LiteSkip('Finance migration 0020 not applied to crm_local');
        }
        $finance = (int) $a->pdo->query("SELECT id FROM modules WHERE `key` = 'finance'")->fetchColumn();
        $a->pdo->prepare("UPDATE modules SET status = 'active' WHERE id = ?")->execute([$finance]);
        $a->pdo->prepare('DELETE FROM organization_modules WHERE organization_id = ? AND module_id = ?')->execute([$a->orgId, $finance]);
        $a->pdo->prepare('INSERT INTO organization_modules (organization_id, module_id, enabled) VALUES (?, ?, 1)')->execute([$a->orgId, $finance]);
        $a->as($a->admin);
        $a->locale('en');
        $h->cur = array_map('intval', $a->pdo->query('SELECT id FROM currencies WHERE is_active = 1 ORDER BY id LIMIT 2')->fetchAll(\PDO::FETCH_COLUMN));
        $h->team = (int) $a->pdo->query("SELECT id FROM teams WHERE organization_id = {$a->orgId} ORDER BY id LIMIT 1")->fetchColumn();
        $h->ready = true;
    }

    return $a;
};
$k = fn ($a) => $a->container();
$engine = fn ($a) => $k($a)->make(\App\Infrastructure\Authorization\AuthorizationEngine::class);
$expenseSvc = fn ($a): ExpenseService => new ExpenseService(new ExpenseRepository(), $engine($a), $k($a)->make(\App\Application\Services\TimelineService::class),
    $k($a)->make(\App\Application\Services\AttachmentService::class), $k($a)->make(\App\Infrastructure\Records\RecordTypeRegistry::class));
/** Built with the same arguments FinanceModule passes (the route tests cover the real wiring). */
$svc = fn ($a): BudgetService => new BudgetService(new BudgetRepository(), $engine($a), $k($a)->make(\App\Application\Services\TimelineService::class),
    new ExpenseRepository(), $expenseSvc($a), $k($a)->make(\App\Infrastructure\Records\RecordTypeRegistry::class));
$project = function ($a, string $status = 'active', ?array $owner = null, ?int $team = null): int {
    $a->as($a->admin);
    $id = (int) ProjectsLite::service($a)->create($a->admin, ['name' => 'LiteTest S5 project ' . bin2hex(random_bytes(2))])['id'];
    $a->pdo->prepare('UPDATE projects SET status = ?, owner_id = ?, team_id = ? WHERE id = ?')->execute([$status, (int) ($owner ?? $a->admin)['id'], $team, $id]);

    return $id;
};
$userWith = function ($a, array $permissions, array $extra = []): array {
    $a->pdo->prepare('INSERT INTO roles (organization_id, `key`, name, is_system) VALUES (?, ?, ?, 0)')
        ->execute([$a->orgId, 'litetest_s5_' . bin2hex(random_bytes(4)), 'LiteTest S5']);
    $roleId = (int) $a->pdo->lastInsertId();
    foreach ($permissions as $key => $scope) {
        $a->pdo->prepare('INSERT INTO role_permissions (role_id, permission_key, scope) VALUES (?, ?, ?)')->execute([$roleId, $key, $scope]);
    }

    return array_merge($a->member, ['role_id' => $roleId], $extra);
};
$editor = ['finance.budgets.view' => 'organization', 'finance.budgets.manage' => 'organization', 'projects.view' => 'organization', 'projects.edit' => 'organization'];
$as = function ($a, array $user, callable $fn) {
    $a->as($user);
    try {
        return $fn();
    } finally {
        $a->as($a->admin);
    }
};
$line = fn (string $category, int $currency, string $amount, string $notes = ''): array => ['category' => $category, 'currency_id' => (string) $currency, 'amount' => $amount, 'notes' => $notes];
$lines = fn ($a, int $p): array => $a->pdo->query("SELECT category, currency_id, amount, notes FROM finance_budget_lines WHERE project_id = {$p} AND organization_id = {$a->orgId} ORDER BY currency_id, category")->fetchAll(\PDO::FETCH_ASSOC);
$count = fn ($a): int => (int) $a->pdo->query('SELECT COUNT(*) FROM finance_budget_lines')->fetchColumn();
$events = fn ($a, int $p): array => $a->pdo->query("SELECT type, description FROM timeline_events WHERE subject_type = 'project' AND subject_id = {$p} AND type = 'budget_updated' ORDER BY id")->fetchAll(\PDO::FETCH_ASSOC);
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
/** A recorded expense row written directly (Stage 4 table, owner = admin). */
$expense = function ($a, int $p, int $cur, string $amount, string $category = 'labor', array $extra = []): int {
    $row = $extra + ['organization_id' => $a->orgId, 'entry_type' => 'expense', 'category' => $category, 'description' => 'LiteTest S5 expense', 'expense_date' => date('Y-m-d'),
        'amount' => $amount, 'currency_id' => $cur, 'project_id' => $p, 'owner_id' => (int) $a->admin['id'], 'status' => 'recorded'];
    $a->pdo->prepare('INSERT INTO finance_expenses (' . implode(', ', array_keys($row)) . ') VALUES (' . implode(', ', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));

    return (int) $a->pdo->lastInsertId();
};
$byCat = function (array $block): array {
    $out = [];
    foreach ($block['rows'] as $r) {
        $out[$r['category']] = $r;
    }

    return $out;
};
$is = fn (?string $expected, ?string $actual, string $msg = '') => $expected === null
    ? LiteAssert::same(null, $actual, $msg)
    : LiteAssert::same(0, bccomp($expected, (string) $actual, 18), $msg . " expected {$expected}, got " . var_export($actual, true));

$tests = [];

$tests['1. create through the route: lines per category × currency, zero allowed, generic timeline; nothing else written'] = function () use ($boot, $h, $project, $line, $lines, $events) {
    $a = $boot();
    $p = $project($a);
    $before = $a->pdo->query('SELECT (SELECT COUNT(*) FROM invoices), (SELECT COUNT(*) FROM credit_notes), (SELECT COUNT(*) FROM finance_expenses), (SELECT COUNT(*) FROM finance_document_links)')->fetch(\PDO::FETCH_NUM);
    $r = $a->request('POST', "/finance/projects/{$p}/budget", [], ['lines' => [$line('labor', $h->cur[0], '1,000.5', 'crew'), $line('shipping', $h->cur[0], '0'), $line('', 0, '', '')]]);
    LiteAssert::same([302, "/projects/{$p}"], [$r['status'], $r['location']]);
    $saved = $lines($a, $p);
    LiteAssert::same(2, count($saved));
    LiteAssert::same(['labor', 'crew'], [$saved[0]['category'], $saved[0]['notes']]);
    LiteAssert::same(0, bccomp('1000.5', $saved[0]['amount'], 18));
    LiteAssert::same(['shipping', null], [$saved[1]['category'], $saved[1]['notes']]);
    LiteAssert::same(0, bccomp('0', $saved[1]['amount'], 18), 'zero allowed');
    LiteAssert::same([['type' => 'budget_updated', 'description' => 'Budget updated']], $events($a, $p));
    LiteAssert::same($before, $a->pdo->query('SELECT (SELECT COUNT(*) FROM invoices), (SELECT COUNT(*) FROM credit_notes), (SELECT COUNT(*) FROM finance_expenses), (SELECT COUNT(*) FROM finance_document_links)')->fetch(\PDO::FETCH_NUM));
};

$tests['2. edit and replace: the saved set is exactly the last submission; an empty submission removes the budget; a no-op save writes no timeline'] = function () use ($boot, $h, $project, $svc, $line, $lines, $events) {
    $a = $boot();
    $p = $project($a);
    $svc($a)->replace($a->admin, $p, ['lines' => [$line('labor', $h->cur[0], '100'), $line('services', $h->cur[0], '50')]]);
    $svc($a)->replace($a->admin, $p, ['lines' => [$line('labor', $h->cur[0], '120'), $line('other', $h->cur[0], '5')]]); // edit + replace
    LiteAssert::same(['labor', 'other'], array_column($lines($a, $p), 'category'), 'services removed, other added');
    LiteAssert::same(0, bccomp('120', $lines($a, $p)[0]['amount'], 18));
    LiteAssert::same(2, count($events($a, $p)));
    $svc($a)->replace($a->admin, $p, ['lines' => [$line('other', $h->cur[0], '5.000'), $line('labor', $h->cur[0], '120')]]); // same budget
    LiteAssert::same(2, count($events($a, $p)), 'no-op: no new timeline entry');
    $svc($a)->replace($a->admin, $p, ['lines' => []]);
    LiteAssert::same([], $lines($a, $p), 'empty submission = no budget');
    LiteAssert::same(3, count($events($a, $p)));
};

$tests['3. validation: negative / non-numeric amount, bad category, unknown currency, long notes, duplicate category × currency, too many lines — nothing written'] = function () use ($boot, $h, $project, $svc, $line, $lines, $refused, $count) {
    $a = $boot();
    $p = $project($a);
    $svc($a)->replace($a->admin, $p, ['lines' => [$line('labor', $h->cur[0], '10')]]);
    $before = $count($a);
    $save = fn (array $rows) => fn () => $svc($a)->replace($a->admin, $p, ['lines' => $rows]);
    foreach (['-5', '-0.01', 'abc', '1e5', ''] as $bad) {
        $refused($save([$line('labor', $h->cur[0], $bad)]), 'lines.0.amount', 'finance.budget.error.invalid_amount');
    }
    $refused($save([$line('travel', $h->cur[0], '1')]), 'lines.0.category', 'finance.budget.error.invalid_category');
    $refused($save([$line('labor', 999999, '1')]), 'lines.0.currency_id', 'finance.error.invalid_currency');
    $inactive = (int) $a->pdo->query('SELECT id FROM currencies WHERE is_active = 0 ORDER BY id LIMIT 1')->fetchColumn();
    if ($inactive > 0) {
        $refused($save([$line('labor', $inactive, '1')]), 'lines.0.currency_id');
    }
    $refused($save([$line('labor', $h->cur[0], '1', str_repeat('n', 256))]), 'lines.0.notes', 'finance.budget.error.notes_too_long');
    $refused($save([$line('labor', $h->cur[0], '1'), $line('labor', $h->cur[0], '2')]), 'lines.1.category', 'finance.budget.error.duplicate_line');
    $refused($save(array_fill(0, 201, $line('', 0, ''))), 'budget', 'finance.budget.error.too_many_lines');
    LiteAssert::same($before, $count($a), 'nothing written');
    LiteAssert::same(0, bccomp('10', $lines($a, $p)[0]['amount'], 18), 'previous budget kept');
    if (count($h->cur) > 1) {
        $svc($a)->replace($a->admin, $p, ['lines' => [$line('labor', $h->cur[0], '1'), $line('labor', $h->cur[1], '2')]]); // same category, other currency: fine
    }
    $r = $a->request('POST', "/finance/projects/{$p}/budget", [], ['lines' => [$line('labor', $h->cur[0], '-1')]]);
    LiteAssert::same(422, $r['status']);
    LiteAssert::contains('field-error', $r['body']);
};

$tests['4. permissions: view-only / no projects.edit / no manage → 403; project not viewable → 404; member without keys → 403'] = function () use ($boot, $h, $project, $userWith, $editor, $as, $line, $count) {
    $a = $boot();
    $p = $project($a);
    $body = ['lines' => [$line('labor', $h->cur[0], '1')]];
    $before = $count($a);
    LiteAssert::same('forbidden', $as($a, $a->member, fn () => $a->outcome('GET', "/finance/projects/{$p}/budget")));
    $viewOnly = $userWith($a, ['finance.budgets.view' => 'organization', 'projects.view' => 'organization', 'projects.edit' => 'organization']);
    LiteAssert::same('forbidden', $as($a, $viewOnly, fn () => $a->outcome('POST', "/finance/projects/{$p}/budget", [], $body)));
    $noProjectEdit = $userWith($a, array_diff_key($editor, ['projects.edit' => 1]));
    LiteAssert::same('forbidden', $as($a, $noProjectEdit, fn () => $a->outcome('GET', "/finance/projects/{$p}/budget")));
    LiteAssert::same('forbidden', $as($a, $noProjectEdit, fn () => $a->outcome('POST', "/finance/projects/{$p}/budget", [], $body)));
    $blind = $userWith($a, array_diff_key($editor, ['projects.view' => 1]));
    LiteAssert::same('notfound', $as($a, $blind, fn () => $a->outcome('POST', "/finance/projects/{$p}/budget", [], $body)));
    LiteAssert::same($before, $count($a));
    $full = $userWith($a, $editor);
    LiteAssert::same(302, $as($a, $full, fn () => $a->request('POST', "/finance/projects/{$p}/budget", [], $body)['status']));
};

$tests['5. project scope: own / team scopes on budgets.manage, budgets.view and projects.edit follow the PROJECT row'] = function () use ($boot, $h, $project, $svc, $userWith, $line) {
    $a = $boot();
    $ownAll = ['finance.budgets.view' => 'own', 'finance.budgets.manage' => 'own', 'projects.view' => 'organization', 'projects.edit' => 'organization'];
    $own = $userWith($a, $ownAll);
    $adminProject = $project($a);
    $theirProject = $project($a, 'active', $own);
    LiteAssert::throws(AuthorizationException::class, fn () => $svc($a)->replace($own, $adminProject, ['lines' => [$line('labor', $h->cur[0], '1')]]));
    $svc($a)->replace($own, $theirProject, ['lines' => [$line('labor', $h->cur[0], '1')]]);
    LiteAssert::false($svc($a)->canView($own, $svc($a)->project($own, $adminProject)), 'budgets.view own: not on the admin project');
    LiteAssert::true($svc($a)->canView($own, $svc($a)->project($own, $theirProject)));
    $projectEditOwn = $userWith($a, ['projects.edit' => 'own'] + ['finance.budgets.view' => 'organization', 'finance.budgets.manage' => 'organization', 'projects.view' => 'organization']);
    LiteAssert::throws(AuthorizationException::class, fn () => $svc($a)->replace($projectEditOwn, $adminProject, ['lines' => [$line('labor', $h->cur[0], '1')]]), 'projects.edit own');
    if ($h->team > 0) {
        $team = $userWith($a, ['finance.budgets.view' => 'team', 'finance.budgets.manage' => 'team', 'projects.view' => 'organization', 'projects.edit' => 'organization'], ['team_id' => $h->team]);
        $teamProject = $project($a, 'active', null, $h->team);
        $svc($a)->replace($team, $teamProject, ['lines' => [$line('labor', $h->cur[0], '2')]]);
        LiteAssert::throws(AuthorizationException::class, fn () => $svc($a)->replace($team, $adminProject, ['lines' => [$line('labor', $h->cur[0], '2')]]), 'other team');
    }
};

$tests['6. status: draft / planned / active / on_hold editable; completed / cancelled read-only (GET and POST refused, nothing written)'] = function () use ($boot, $h, $project, $svc, $line, $lines, $refused) {
    $a = $boot();
    foreach (['draft', 'planned', 'active', 'on_hold'] as $status) {
        $p = $project($a, $status);
        LiteAssert::same(200, $a->request('GET', "/finance/projects/{$p}/budget")['status'], $status);
        $svc($a)->replace($a->admin, $p, ['lines' => [$line('labor', $h->cur[0], '1')]]);
        LiteAssert::same(1, count($lines($a, $p)), $status);
    }
    foreach (['completed', 'cancelled'] as $status) {
        $p = $project($a, 'active');
        $svc($a)->replace($a->admin, $p, ['lines' => [$line('labor', $h->cur[0], '7')]]);
        $a->pdo->exec("UPDATE projects SET status = '{$status}' WHERE id = {$p}");
        $refused(fn () => $svc($a)->replace($a->admin, $p, ['lines' => [$line('labor', $h->cur[0], '8')]]), 'budget', 'finance.budget.error.read_only');
        $g = $a->request('GET', "/finance/projects/{$p}/budget");
        LiteAssert::same([302, "/projects/{$p}"], [$g['status'], $g['location']], "{$status} GET");
        $r = $a->request('POST', "/finance/projects/{$p}/budget", [], ['lines' => [$line('labor', $h->cur[0], '9')]]);
        LiteAssert::same([302, "/projects/{$p}"], [$r['status'], $r['location']], "{$status} POST");
        LiteAssert::same(0, bccomp('7', $lines($a, $p)[0]['amount'], 18), "{$status}: unchanged");
        $panel = $a->request('GET', "/projects/{$p}")['body'];
        LiteAssert::notContains("/finance/projects/{$p}/budget\"", $panel, "{$status}: no edit button");
    }
};

$tests['7. tenant isolation: foreign project → 404; another organization\'s lines for the same project id are never read, replaced or deleted'] = function () use ($boot, $h, $project, $svc, $line, $lines) {
    $a = $boot();
    if ($a->otherOrgId === null) {
        throw new LiteSkip('only one organization');
    }
    $foreign = ProjectsLite::foreignProject($a);
    LiteAssert::same('notfound', $a->outcome('GET', "/finance/projects/{$foreign}/budget"));
    LiteAssert::same('notfound', $a->outcome('POST', "/finance/projects/{$foreign}/budget", [], ['lines' => [$line('labor', $h->cur[0], '1')]]));
    LiteAssert::throws(NotFoundException::class, fn () => $svc($a)->replace($a->admin, $foreign, ['lines' => []]));
    $p = $project($a);
    $insertForeign = $a->pdo->prepare('INSERT INTO finance_budget_lines (organization_id, project_id, category, currency_id, amount) VALUES (?, ?, ?, ?, 555)');
    $insertForeign->execute([$a->otherOrgId, $p, 'labor', $h->cur[0]]);
    $insertForeign->execute([$a->otherOrgId, $p, 'services', $h->cur[0]]);
    $svc($a)->replace($a->admin, $p, ['lines' => [$line('labor', $h->cur[0], '1')]]); // same category × currency in OUR org: no clash
    LiteAssert::same(1, count($lines($a, $p)));
    $panel = $svc($a)->panel($a->admin, $svc($a)->project($a->admin, $p));
    LiteAssert::same(['labor'], array_column($panel['currencies'][$h->cur[0]]['rows'], 'category'), 'no foreign "services" row');
    LiteAssert::same(0, bccomp('1', $panel['currencies'][$h->cur[0]]['total']['budget'], 18), 'the foreign 555 lines are not read');
    $form = $a->request('GET', "/finance/projects/{$p}/budget")['body'];
    LiteAssert::notContains('value="555"', $form, 'nor shown in the form');
    $svc($a)->replace($a->admin, $p, ['lines' => []]);
    LiteAssert::same(2, (int) $a->pdo->query("SELECT COUNT(*) FROM finance_budget_lines WHERE organization_id = {$a->otherOrgId} AND project_id = {$p}")->fetchColumn(), 'foreign lines untouched');
};

$tests['8. CSRF on the budget POST'] = function () use ($boot, $h, $project, $line, $count) {
    $a = $boot();
    $p = $project($a);
    $before = $count($a);
    LiteAssert::same('csrf', $a->outcome('POST', "/finance/projects/{$p}/budget", [], ['_csrf' => 'bad', 'lines' => [$line('labor', $h->cur[0], '1')]]));
    LiteAssert::same($before, $count($a));
};

$tests['9. timeline: one generic "Budget updated" on the project — no amount, category or note'] = function () use ($boot, $h, $project, $svc, $line) {
    $a = $boot();
    $p = $project($a);
    $svc($a)->replace($a->admin, $p, ['lines' => [$line('contractor', $h->cur[0], '98765.43', 'secret-note')]]);
    $all = json_encode($a->pdo->query("SELECT type, description FROM timeline_events WHERE subject_type = 'project' AND subject_id = {$p}")->fetchAll(\PDO::FETCH_ASSOC));
    foreach (['98765', 'secret-note', 'contractor'] as $secret) {
        LiteAssert::notContains($secret, $all, $secret);
    }
    LiteAssert::contains('Budget updated', $all);
};

$tests['10. Budget vs Actual: Remaining = Budget − Actual, Variance = Actual − Budget, Variance % only when Budget > 0, "—" without a line; corrections and void follow Stage 4'] = function () use ($boot, $h, $project, $svc, $line, $expense, $byCat, $is) {
    $a = $boot();
    $p = $project($a);
    $svc($a)->replace($a->admin, $p, ['lines' => [$line('labor', $h->cur[0], '100'), $line('shipping', $h->cur[0], '0')]]);
    $orig = $expense($a, $p, $h->cur[0], '50', 'labor');
    $expense($a, $p, $h->cur[0], '20', 'labor', ['entry_type' => 'correction', 'correction_of_id' => $orig, 'correction_direction' => 'decrease']);
    $expense($a, $p, $h->cur[0], '5', 'labor', ['entry_type' => 'correction', 'correction_of_id' => $orig, 'correction_direction' => 'increase']);
    $expense($a, $p, $h->cur[0], '999', 'labor', ['status' => 'void']);
    $expense($a, $p, $h->cur[0], '10', 'procurement');
    $data = $svc($a)->panel($a->admin, $svc($a)->project($a->admin, $p));
    $rows = $byCat($data['currencies'][$h->cur[0]]);
    // labor: actual 50 − 20 + 5 = 35 (void excluded)
    $is('100', $rows['labor']['budget']);
    $is('35', $rows['labor']['actual']);
    $is('65', $rows['labor']['remaining']);
    $is('-65', $rows['labor']['variance']);
    $is('-65', $rows['labor']['variance_pct']);
    // procurement: actual without a budget line → all budget figures "—"
    $is(null, $rows['procurement']['budget']);
    $is('10', $rows['procurement']['actual']);
    $is(null, $rows['procurement']['remaining']);
    $is(null, $rows['procurement']['variance']);
    $is(null, $rows['procurement']['variance_pct']);
    // shipping: zero budget → variance but no variance %
    $is('0', $rows['shipping']['budget']);
    $is('0', $rows['shipping']['remaining']);
    $is('0', $rows['shipping']['variance']);
    $is(null, $rows['shipping']['variance_pct']);
    $total = $data['currencies'][$h->cur[0]]['total'];
    $is('100', $total['budget']);
    $is('45', $total['actual']);
    $is('55', $total['remaining']);
    $is('-55', $total['variance']);
    $is('-55', $total['variance_pct']);
    LiteAssert::true($data['has_budget'] && $data['show_actual'] && $data['can_edit'] && !$data['read_only']);
    // over budget: positive variance
    $expense($a, $p, $h->cur[0], '100', 'labor');
    $rows = $byCat($svc($a)->panel($a->admin, $svc($a)->project($a->admin, $p))['currencies'][$h->cur[0]]);
    $is('35', $rows['labor']['variance']);
    $is('-35', $rows['labor']['remaining']);
    $is('35', $rows['labor']['variance_pct']);
};

$tests['11. currency and category isolation: each currency its own block and total, no cross-currency sum; an expense counts only in its own category'] = function () use ($boot, $h, $project, $svc, $line, $expense, $byCat, $is) {
    $a = $boot();
    if (count($h->cur) < 2) {
        throw new LiteSkip('needs two active currencies');
    }
    [$c1, $c2] = $h->cur;
    $p = $project($a);
    $svc($a)->replace($a->admin, $p, ['lines' => [$line('labor', $c1, '100'), $line('labor', $c2, '7'), $line('services', $c2, '3')]]);
    $expense($a, $p, $c1, '40', 'labor');
    $expense($a, $p, $c2, '1', 'services');
    $data = $svc($a)->panel($a->admin, $svc($a)->project($a->admin, $p));
    LiteAssert::same([$c1, $c2], array_keys($data['currencies']));
    $is('100', $data['currencies'][$c1]['total']['budget']);
    $is('40', $data['currencies'][$c1]['total']['actual']);
    $is('10', $data['currencies'][$c2]['total']['budget']);
    $is('1', $data['currencies'][$c2]['total']['actual']);
    $r2 = $byCat($data['currencies'][$c2]);
    $is('0', $r2['labor']['actual'], 'the c1 labor expense is not in c2');
    $is('1', $r2['services']['actual']);
    LiteAssert::false(isset($byCat($data['currencies'][$c1])['services']), 'no services row in c1');
    LiteAssert::same(['currencies', 'has_budget', 'show_actual', 'can_edit', 'read_only'], array_keys($data), 'no combined total');
};

$tests['12. without finance.expenses.view the panel shows the budget only (no Actual / Remaining / Variance); restricted expenses never counted'] = function () use ($boot, $h, $project, $svc, $line, $expense, $userWith, $as, $is) {
    $a = $boot();
    $p = $project($a);
    $svc($a)->replace($a->admin, $p, ['lines' => [$line('labor', $h->cur[0], '100')]]);
    $expense($a, $p, $h->cur[0], '30', 'labor');
    $budgetOnly = $userWith($a, ['finance.budgets.view' => 'organization', 'projects.view' => 'organization']);
    $data = $svc($a)->panel($budgetOnly, $svc($a)->project($budgetOnly, $p));
    LiteAssert::false($data['show_actual']);
    $is(null, $data['currencies'][$h->cur[0]]['rows'][0]['actual']);
    $is(null, $data['currencies'][$h->cur[0]]['rows'][0]['variance']);
    $body = $as($a, $budgetOnly, fn () => $a->request('GET', "/projects/{$p}")['body']);
    LiteAssert::contains('data-related-panel="finance.project_budget"', $body);
    LiteAssert::notContains('>Actual<', $body);
    $ownExpenses = $userWith($a, ['finance.budgets.view' => 'organization', 'projects.view' => 'organization', 'finance.expenses.view' => 'own']);
    $is('0', $svc($a)->panel($ownExpenses, $svc($a)->project($ownExpenses, $p))['currencies'][$h->cur[0]]['rows'][0]['actual'], 'admin\'s expense not visible to own scope');
};

$tests['13. project UI: Budget panel order 47 with "No budget defined" + "Define budget"; hidden without finance.budgets.view; 5 languages, no raw keys'] = function () use ($boot, $h, $project, $svc, $line, $userWith, $as) {
    $a = $boot();
    $p = $project($a);
    $body = $a->request('GET', "/projects/{$p}")['body'];
    LiteAssert::contains('data-related-panel="finance.project_budget"', $body);
    LiteAssert::contains('No budget defined.', $body);
    LiteAssert::contains("href=\"/finance/projects/{$p}/budget\"", $body);
    LiteAssert::contains('Define budget', $body);
    LiteAssert::true(strpos($body, 'data-related-panel="finance.project_costs"') < strpos($body, 'data-related-panel="finance.project_budget"'), 'after Project Costs (46)');
    $noBudget = $userWith($a, ['projects.view' => 'organization', 'finance.expenses.view' => 'organization']);
    LiteAssert::notContains('data-related-panel="finance.project_budget"', $as($a, $noBudget, fn () => $a->request('GET', "/projects/{$p}")['body']));
    $svc($a)->replace($a->admin, $p, ['lines' => [$line('labor', $h->cur[0], '100')]]);
    try {
        foreach (['en', 'fa', 'ar', 'tr', 'fr'] as $locale) {
            $a->locale($locale);
            $lang = require dirname(__DIR__, 2) . "/lang/{$locale}.php";
            foreach (["/projects/{$p}", "/finance/projects/{$p}/budget"] as $path) {
                $r = $a->request('GET', $path);
                LiteAssert::same(200, $r['status'], "{$locale} {$path}");
                LiteAssert::notContains('finance.budget.', $r['body'], "{$locale} {$path}: raw key");
                LiteAssert::notContains('finance.profit.', $r['body'], "{$locale} {$path}: raw key");
            }
            LiteAssert::contains(htmlspecialchars($lang['finance.budget.title'], ENT_QUOTES), $a->request('GET', "/projects/{$p}")['body'], $locale);
        }
    } finally {
        $a->locale('en');
    }
};

$tests['14. Stage 4 untouched: budgets never change expense rows; the Project Costs panel shows the same figures'] = function () use ($boot, $h, $project, $svc, $line, $expense, $expenseSvc, $engine) {
    $a = $boot();
    $p = $project($a);
    $expense($a, $p, $h->cur[0], '12', 'labor');
    $costs = fn () => $expenseSvc($a)->projectPanel($a->admin, $expenseSvc($a)->viewableProject($a->admin, $p), fn (string $key): bool => $engine($a)->can($a->admin, $key))['totals'];
    $rows = fn () => $a->pdo->query("SELECT * FROM finance_expenses WHERE project_id = {$p} ORDER BY id")->fetchAll(\PDO::FETCH_ASSOC);
    [$beforeCosts, $beforeRows] = [$costs(), $rows()];
    $svc($a)->replace($a->admin, $p, ['lines' => [$line('labor', $h->cur[0], '1')]]);
    LiteAssert::same([$beforeCosts, $beforeRows], [$costs(), $rows()]);
};

$tests['rollback leaves no test data behind'] = function () use ($boot) {
    $a = $boot();
    $a->rollBack();
    LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM projects WHERE name LIKE 'LiteTest S5%'")->fetchColumn());
    LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM finance_expenses WHERE description LIKE 'LiteTest S5%'")->fetchColumn());
    LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM roles WHERE `key` LIKE 'litetest_s5_%'")->fetchColumn());
    LiteAssert::same(0, (int) $a->pdo->query('SELECT COUNT(*) FROM finance_budget_lines')->fetchColumn());
};

return $tests;
