<?php

declare(strict_types=1);

/**
 * Stage 5 — project profit and margin (docs/project-finance-design.md).
 *
 * Per currency, no FX, never a cross-currency total:
 *   Net revenue = Σ issued attributed invoices: (grand − tax) − credited × (grand − tax) / grand  (0 when grand = 0)
 *   Actual cost = Σ signed recorded expense rows (Stage 4)
 *   Profit = Net revenue − Actual cost; Margin % = Profit / Revenue × 100 only when Revenue > 0.
 * Visible only with ALL of finance.budgets.view (project scope), invoices.view at
 * organization / all, credit_notes.view, finance.expenses.view at organization / all
 * — otherwise nothing (no partial profit).
 *
 * crm_local only, one transaction, rolled back.
 */

require_once __DIR__ . '/Support/LocalApp.php';
require_once __DIR__ . '/Support/ProjectsLite.php';

use Modules\Finance\ExpenseRepository;
use Modules\Finance\ProjectRevenueRepository;
use Modules\Finance\Services\ExpenseService;
use Modules\Finance\Services\ProjectProfitService;
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
        $h->ready = true;
    }

    return $a;
};
$k = fn ($a) => $a->container();
$engine = fn ($a) => $k($a)->make(\App\Infrastructure\Authorization\AuthorizationEngine::class);
/** Built with the same arguments FinanceModule passes (the route tests cover the real wiring). */
$svc = fn ($a): ProjectProfitService => new ProjectProfitService(new ProjectRevenueRepository(), new ExpenseRepository(),
    new ExpenseService(new ExpenseRepository(), $engine($a), $k($a)->make(\App\Application\Services\TimelineService::class),
        $k($a)->make(\App\Application\Services\AttachmentService::class), $k($a)->make(\App\Infrastructure\Records\RecordTypeRegistry::class)),
    $engine($a));
$project = function ($a, string $status = 'active', ?array $owner = null): int {
    $a->as($a->admin);
    $id = (int) ProjectsLite::service($a)->create($a->admin, ['name' => 'LiteTest S5P project ' . bin2hex(random_bytes(2))])['id'];
    $a->pdo->prepare('UPDATE projects SET status = ?, owner_id = ? WHERE id = ?')->execute([$status, (int) ($owner ?? $a->admin)['id'], $id]);

    return $id;
};
$row = fn ($a, int $p): array => (new \Modules\Projects\ProjectRepository())->findById($p) ?? [];
/** An invoice written directly, attributed to $p unless $p is null; optional credit note. */
$invoice = function ($a, ?int $p, int $cur, string $grand, string $tax, string $status = 'issued', ?string $credited = null, ?array $owner = null): int {
    $a->pdo->prepare("INSERT INTO invoices (organization_id, owner_id, issue_date, currency_id, subtotal, tax_total, grand_total, status, notes) VALUES (?, ?, CURDATE(), ?, ?, ?, ?, ?, 'LiteTest S5P')")
        ->execute([$a->orgId, (int) ($owner ?? $a->admin)['id'], $cur, bcsub($grand, $tax, 18), $tax, $grand, $status]);
    $id = (int) $a->pdo->lastInsertId();
    if ($p !== null) {
        $a->pdo->prepare("INSERT INTO finance_document_links (organization_id, document_type, document_id, linked_type, linked_id, linked_label) VALUES (?, 'invoice', ?, 'project', ?, 'LiteTest S5P')")
            ->execute([$a->orgId, $id, $p]);
    }
    if ($credited !== null) {
        $a->pdo->prepare("INSERT INTO credit_notes (organization_id, invoice_id, credit_note_number, issue_date, reason, amount, issued_by_user_id) VALUES (?, ?, ?, CURDATE(), 'LiteTest S5P', ?, ?)")
            ->execute([$a->orgId, $id, 'LT5-' . bin2hex(random_bytes(4)), $credited, (int) $a->admin['id']]);
    }

    return $id;
};
$expense = function ($a, int $p, int $cur, string $amount, array $extra = []): int {
    $row = $extra + ['organization_id' => $a->orgId, 'entry_type' => 'expense', 'category' => 'labor', 'description' => 'LiteTest S5P expense', 'expense_date' => date('Y-m-d'),
        'amount' => $amount, 'currency_id' => $cur, 'project_id' => $p, 'owner_id' => (int) $a->admin['id'], 'status' => 'recorded'];
    $a->pdo->prepare('INSERT INTO finance_expenses (' . implode(', ', array_keys($row)) . ') VALUES (' . implode(', ', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));

    return (int) $a->pdo->lastInsertId();
};
$userWith = function ($a, array $permissions): array {
    $a->pdo->prepare('INSERT INTO roles (organization_id, `key`, name, is_system) VALUES (?, ?, ?, 0)')
        ->execute([$a->orgId, 'litetest_s5p_' . bin2hex(random_bytes(4)), 'LiteTest S5P']);
    $roleId = (int) $a->pdo->lastInsertId();
    foreach ($permissions as $key => $scope) {
        $a->pdo->prepare('INSERT INTO role_permissions (role_id, permission_key, scope) VALUES (?, ?, ?)')->execute([$roleId, $key, $scope]);
    }

    return array_merge($a->member, ['role_id' => $roleId]);
};
$four = ['finance.budgets.view' => 'organization', 'invoices.view' => 'organization', 'credit_notes.view' => 'organization', 'finance.expenses.view' => 'organization', 'projects.view' => 'organization'];
$as = function ($a, array $user, callable $fn) {
    $a->as($user);
    try {
        return $fn();
    } finally {
        $a->as($a->admin);
    }
};
$is = fn (?string $expected, ?string $actual, string $msg = '') => $expected === null
    ? LiteAssert::same(null, $actual, $msg)
    : LiteAssert::same(0, bccomp($expected, (string) $actual, 18), $msg . " expected {$expected}, got " . var_export($actual, true));
$profit = fn ($a, int $p, ?array $user = null) => $svc($a)->profitFor($user ?? $a->admin, $row($a, $p));

$tests = [];

$tests['1. revenue: tax excluded; credit note in the invoice\'s own net / gross proportion'] = function () use ($boot, $h, $project, $invoice, $profit, $is) {
    $a = $boot();
    $p = $project($a);
    $invoice($a, $p, $h->cur[0], '1150', '150');                // net 1000
    $invoice($a, $p, $h->cur[0], '1150', '150', 'issued', '230'); // net 1000 − 230 × 1000/1150 = 800
    $invoice($a, $p, $h->cur[0], '500', '0', 'issued', '100');   // no tax: 500 − 100 = 400
    $f = $profit($a, $p)[$h->cur[0]];
    $is('2200', $f['revenue']);
    $is('0', $f['cost']);
    $is('2200', $f['profit']);
    $is('100', $f['margin']);
};

$tests['2. only issued invoices attributed to the project: draft, cancelled (with its credit note) and unattributed invoices excluded; zero grand_total gives 0'] = function () use ($boot, $h, $project, $invoice, $profit, $is) {
    $a = $boot();
    $p = $project($a);
    $other = $project($a);
    $invoice($a, $p, $h->cur[0], '100', '0');
    $invoice($a, $p, $h->cur[0], '7000', '0', 'draft');
    $invoice($a, $p, $h->cur[0], '8000', '0', 'cancelled', '300');
    $invoice($a, null, $h->cur[0], '9000', '0');
    $invoice($a, $other, $h->cur[0], '6000', '0');
    $invoice($a, $p, $h->cur[0], '0', '0');                   // grand 0 → 0, no division by zero
    $invoice($a, $p, $h->cur[0], '0', '0', 'issued', '0');
    $is('100', $profit($a, $p)[$h->cur[0]]['revenue']);
    $svcRevenue = $profit($a, $p);
    LiteAssert::same([$h->cur[0]], array_keys($svcRevenue));
};

$tests['3. actual cost: original +, increase +, decrease −, void excluded (Stage 4 signed); negative profit; zero revenue → margin "—"'] = function () use ($boot, $h, $project, $invoice, $expense, $profit, $is) {
    $a = $boot();
    $p = $project($a);
    $orig = $expense($a, $p, $h->cur[0], '300');
    $expense($a, $p, $h->cur[0], '50', ['entry_type' => 'correction', 'correction_of_id' => $orig, 'correction_direction' => 'increase']);
    $expense($a, $p, $h->cur[0], '20', ['entry_type' => 'correction', 'correction_of_id' => $orig, 'correction_direction' => 'decrease']);
    $expense($a, $p, $h->cur[0], '999', ['status' => 'void']);
    $expense($a, $p, $h->cur[0], '7', ['entry_type' => 'correction', 'correction_of_id' => $orig, 'correction_direction' => 'increase', 'status' => 'void']);
    $f = $profit($a, $p)[$h->cur[0]];
    $is('330', $f['cost']);
    $is('0', $f['revenue']);
    $is('-330', $f['profit'], 'negative profit');
    $is(null, $f['margin'], 'no revenue → no margin');
    $invoice($a, $p, $h->cur[0], '1100', '100'); // revenue 1000
    $f = $profit($a, $p)[$h->cur[0]];
    $is('670', $f['profit']);
    $is('67', $f['margin']);
};

$tests['4. margin = profit / revenue × 100 (exact), shown with one decimal; a loss gives a negative margin'] = function () use ($boot, $h, $project, $invoice, $expense, $profit, $is, $svc) {
    $a = $boot();
    $p = $project($a);
    $invoice($a, $p, $h->cur[0], '800', '0');
    $expense($a, $p, $h->cur[0], '330');
    $f = $profit($a, $p)[$h->cur[0]];
    $is('470', $f['profit']);
    $is('58.75', $f['margin']);
    $body = $a->request('GET', "/projects/{$p}")['body'];
    LiteAssert::contains('58.8%', $body, 'one decimal, half up');
    $expense($a, $p, $h->cur[0], '1000');
    $is('-66.25', $profit($a, $p)[$h->cur[0]]['margin'], '(800 − 1330) / 800');
    $is(null, $svc($a)->margin('-5', '0'));
    $is(null, $svc($a)->margin('5', '-1'));
};

$tests['5. currencies kept apart: one block per currency, no combined total, no conversion'] = function () use ($boot, $h, $project, $invoice, $expense, $profit, $is) {
    $a = $boot();
    if (count($h->cur) < 2) {
        throw new LiteSkip('needs two active currencies');
    }
    [$c1, $c2] = $h->cur;
    $p = $project($a);
    $invoice($a, $p, $c1, '10000', '0');
    $expense($a, $p, $c2, '3000');
    $all = $profit($a, $p);
    LiteAssert::same([$c1, $c2], array_keys($all), 'only currency keys — no total');
    $is('10000', $all[$c1]['profit']);
    $is('0', $all[$c1]['cost']);
    $is('-3000', $all[$c2]['profit']);
    $is(null, $all[$c2]['margin']);
    $body = $a->request('GET', "/projects/{$p}")['body'];
    LiteAssert::notContains('7,000', $body, 'no cross-currency sum rendered');
};

$tests['6. security: all four conditions needed — missing budgets / invoices / credit-note / expense permission or a narrower scope hides everything'] = function () use ($boot, $h, $project, $invoice, $expense, $profit, $userWith, $four, $as) {
    $a = $boot();
    $p = $project($a);
    $invoice($a, $p, $h->cur[0], '4321', '0');
    $expense($a, $p, $h->cur[0], '1234');
    LiteAssert::true($profit($a, $p, $userWith($a, $four)) !== null, 'all four at organization scope');
    $hidden = [
        'no budgets.view' => array_diff_key($four, ['finance.budgets.view' => 1]),
        'no invoices.view' => array_diff_key($four, ['invoices.view' => 1]),
        'no credit_notes.view' => array_diff_key($four, ['credit_notes.view' => 1]),
        'no expenses.view' => array_diff_key($four, ['finance.expenses.view' => 1]),
        'invoices own' => ['invoices.view' => 'own'] + $four,
        'invoices team' => ['invoices.view' => 'team'] + $four,
        'invoices department' => ['invoices.view' => 'department'] + $four,
        'expenses own' => ['finance.expenses.view' => 'own'] + $four,
        'expenses team' => ['finance.expenses.view' => 'team'] + $four,
        'expenses department' => ['finance.expenses.view' => 'department'] + $four,
        'budgets own, not their project' => ['finance.budgets.view' => 'own'] + $four,
    ];
    foreach ($hidden as $case => $perms) {
        $user = $userWith($a, $perms);
        LiteAssert::same(null, $profit($a, $p, $user), $case);
        $body = $as($a, $user, fn () => $a->request('GET', "/projects/{$p}")['body']);
        LiteAssert::notContains('data-related-panel="finance.project_profit"', $body, $case);
        LiteAssert::notContains('3,087', $body, "{$case}: no profit figure");
    }
    $ownBudgets = $userWith($a, ['finance.budgets.view' => 'own'] + $four);
    $theirs = $project($a, 'active', $ownBudgets);
    LiteAssert::true($profit($a, $theirs, $ownBudgets) !== null, 'budgets own on their own project');
};

$tests['7. profitability panel (order 48): figures, the tax note, every project status; hidden for a project the user can\'t see (IDOR)'] = function () use ($boot, $h, $project, $invoice, $expense, $userWith, $four, $as) {
    $a = $boot();
    foreach (['draft', 'planned', 'active', 'on_hold', 'completed', 'cancelled'] as $status) {
        $p = $project($a, $status);
        $invoice($a, $p, $h->cur[0], '1150', '150');
        $expense($a, $p, $h->cur[0], '400');
        $body = $a->request('GET', "/projects/{$p}")['body'];
        LiteAssert::contains('data-related-panel="finance.project_profit"', $body, $status);
        LiteAssert::contains('600', $body, "{$status}: profit");
        LiteAssert::contains('60.0%', $body, "{$status}: margin");
    }
    LiteAssert::contains('Revenue (excl. tax)', $body);
    LiteAssert::contains('Revenue (incl. tax)', $body, 'the note names the Stage 3-B figure');
    LiteAssert::true(strpos($body, 'data-related-panel="finance.project_budget"') < strpos($body, 'data-related-panel="finance.project_profit"'), 'after Budget (47)');
    $blind = $userWith($a, array_diff_key($four, ['projects.view' => 1]));
    LiteAssert::same('forbidden', $as($a, $blind, fn () => $a->outcome('GET', "/projects/{$p}")), 'no projects.view: the Projects route refuses');
    $ownProjects = $userWith($a, ['projects.view' => 'own'] + $four);
    LiteAssert::true(in_array($as($a, $ownProjects, fn () => $a->outcome('GET', "/projects/{$p}")), ['forbidden', 'notfound'], true), 'someone else\'s project');
    $registry = $a->container()->make(\App\Infrastructure\Records\RecordTypeRegistry::class);
    LiteAssert::same(null, $registry->find('project', $p, $ownProjects), 'the panels\' project lookup refuses it too');
    LiteAssert::true($registry->find('project', $p, $a->admin) !== null, 'control: the admin can');
    if ($a->otherOrgId !== null) {
        LiteAssert::same('notfound', $a->outcome('GET', '/projects/' . ProjectsLite::foreignProject($a)));
    }
};

$tests['8. tenant isolation: another organization\'s invoices, credit notes and expenses for the same project id are never counted'] = function () use ($boot, $h, $project, $invoice, $expense, $profit, $is) {
    $a = $boot();
    if ($a->otherOrgId === null) {
        throw new LiteSkip('only one organization');
    }
    $p = $project($a);
    $invoice($a, $p, $h->cur[0], '100', '0');
    $foreignInvoice = $invoice($a, $p, $h->cur[0], '5000', '0');
    $a->pdo->exec("UPDATE invoices SET organization_id = {$a->otherOrgId} WHERE id = {$foreignInvoice}");
    $a->pdo->exec("UPDATE finance_document_links SET organization_id = {$a->otherOrgId} WHERE document_type = 'invoice' AND document_id = {$foreignInvoice}");
    $x = $expense($a, $p, $h->cur[0], '40');
    $a->pdo->exec("UPDATE finance_expenses SET organization_id = {$a->otherOrgId} WHERE id = {$x}");
    $expense($a, $p, $h->cur[0], '10');
    $f = $profit($a, $p)[$h->cur[0]];
    $is('100', $f['revenue']);
    $is('10', $f['cost']);
};

$tests['9. read only: computing profit writes nothing and changes no Stage 3-B / Stage 4 figure'] = function () use ($boot, $h, $project, $invoice, $expense, $profit, $engine, $k) {
    $a = $boot();
    $p = $project($a);
    $invoice($a, $p, $h->cur[0], '1150', '150', 'issued', '115');
    $expense($a, $p, $h->cur[0], '30');
    $revenue = new \Modules\Finance\Services\ProjectRevenueService(new ProjectRevenueRepository(), new \Modules\Finance\FinanceDocumentLinkRepository(), new \Modules\Finance\InvoiceRepository(),
        $engine($a), $k($a)->make(\App\Application\Services\TimelineService::class), $k($a)->make(\App\Infrastructure\Records\RecordTypeRegistry::class));
    $can = fn (string $key): bool => $engine($a)->can($a->admin, $key);
    $snap = fn () => [
        $revenue->panelFor($a->admin, $revenue->project($a->admin, $p), $can)['summary'],
        $a->pdo->query('SELECT (SELECT COUNT(*) FROM invoices), (SELECT COUNT(*) FROM credit_notes), (SELECT COUNT(*) FROM finance_expenses), (SELECT COUNT(*) FROM timeline_events), (SELECT COUNT(*) FROM finance_budget_lines)')->fetch(\PDO::FETCH_NUM),
    ];
    $before = $snap();
    $profit($a, $p);
    $a->request('GET', "/projects/{$p}");
    LiteAssert::same($before, $snap());
    $stage3b = array_values($before[0])[0];
    LiteAssert::same(0, bccomp('1035', (string) $stage3b['net'], 18), 'Stage 3-B keeps its own (incl. tax) figure: 1150 − 115');
    LiteAssert::same(0, bccomp('900', $profit($a, $p)[$h->cur[0]]['revenue'], 18), 'Stage 5: 1000 − 115 × 1000/1150 = 900');
};

$tests['rollback leaves no test data behind'] = function () use ($boot) {
    $a = $boot();
    $a->rollBack();
    LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM projects WHERE name LIKE 'LiteTest S5P%'")->fetchColumn());
    LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM invoices WHERE notes = 'LiteTest S5P'")->fetchColumn());
    LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM credit_notes WHERE reason = 'LiteTest S5P'")->fetchColumn());
    LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM finance_expenses WHERE description LIKE 'LiteTest S5P%'")->fetchColumn());
    LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM roles WHERE `key` LIKE 'litetest_s5p_%'")->fetchColumn());
};

return $tests;
