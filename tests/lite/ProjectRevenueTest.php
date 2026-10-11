<?php

declare(strict_types=1);

/**
 * Stage 3-B — Project revenue (docs/project-finance-design.md).
 *
 * Revenue = issued invoices ATTRIBUTED to the project (finance_document_links,
 * linked_type 'project', one project per invoice), per invoice currency, tax
 * included: gross − credit notes. Payments, drafts, cancelled invoices,
 * proformas, contract value, installments and the contract ↔ project
 * association are never revenue. Only invoices the user may view count;
 * credit figures need credit_notes.view. Attach: invoices.edit + projects.edit;
 * detach only drafts.
 *
 * Issue / payments / credit notes are stored values (their services open
 * their own transaction, which can't nest here). crm_local only, one
 * transaction, rolled back.
 */

require_once __DIR__ . '/Support/LocalApp.php';
require_once __DIR__ . '/Support/ProjectsLite.php';

use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use Modules\Contracts\Services\ContractService;
use Modules\Finance\CreditNoteRepository;
use Modules\Finance\FinanceDocumentLinkRepository;
use Modules\Finance\InstallmentRepository;
use Modules\Finance\InvoiceRepository;
use Modules\Finance\ProformaRepository;
use Modules\Finance\ProjectRevenueRepository;
use Modules\Finance\Services\ContractBillingService;
use Modules\Finance\Services\InstallmentService;
use Modules\Finance\Services\InvoiceService;
use Modules\Finance\Services\ProformaService;
use Modules\Finance\Services\ProjectRevenueService;
use Tests\Lite\Support\ProjectsLite;

$projectsBoot = ProjectsLite::booter(true);
$h = new \stdClass();
$h->ready = false;
$boot = function () use ($projectsBoot, $h) {
    $a = $projectsBoot();
    if (!$h->ready) {
        if ((int) $a->pdo->query('SELECT COUNT(*) FROM currencies WHERE is_active = 1')->fetchColumn() < 3) {
            throw new LiteSkip('needs three active currencies');
        }
        $finance = (int) $a->pdo->query("SELECT id FROM modules WHERE `key` = 'finance'")->fetchColumn();
        $a->pdo->prepare("UPDATE modules SET status = 'active' WHERE id = ?")->execute([$finance]);
        $a->pdo->prepare('DELETE FROM organization_modules WHERE organization_id = ? AND module_id = ?')->execute([$a->orgId, $finance]);
        $a->pdo->prepare('INSERT INTO organization_modules (organization_id, module_id, enabled) VALUES (?, ?, 1)')->execute([$a->orgId, $finance]);
        $a->as($a->admin);
        $a->locale('en');
        $h->cur = array_map('intval', $a->pdo->query('SELECT id FROM currencies WHERE is_active = 1 ORDER BY id LIMIT 3')->fetchAll(\PDO::FETCH_COLUMN));
        $h->ready = true;
    }

    return $a;
};
$k = fn ($a) => $a->container();
$engine = fn ($a) => $k($a)->make(\App\Infrastructure\Authorization\AuthorizationEngine::class);
/** Built with the same arguments FinanceModule passes (routes cover the real wiring). */
$svc = fn ($a): ProjectRevenueService => new ProjectRevenueService(
    new ProjectRevenueRepository(),
    new FinanceDocumentLinkRepository(),
    new InvoiceRepository(),
    $engine($a),
    $k($a)->make(\App\Application\Services\TimelineService::class),
    $k($a)->make(\App\Infrastructure\Records\RecordTypeRegistry::class),
);
$panel = function ($a, int $projectId, ?array $user = null) use ($svc, $engine): array {
    $user ??= $a->admin;
    $project = $svc($a)->project($user, $projectId);
    LiteAssert::true($project !== null, 'project viewable');

    return $svc($a)->panelFor($user, $project, fn (string $key): bool => $engine($a)->can($user, $key));
};
/** The summary entry of one currency, or null. */
$sum = function (array $panel, int $currency): ?array {
    foreach ($panel['summary'] as $s) {
        if ($s['currency_id'] === $currency) {
            return $s;
        }
    }

    return null;
};
$eq = function (?array $s, array $expected, string $label = ''): void {
    LiteAssert::true($s !== null, "{$label}: summary entry exists");
    foreach ($expected as $key => $value) {
        if ($value === null) {
            LiteAssert::same(null, $s[$key], "{$label} {$key}");
        } else {
            LiteAssert::same(0, bccomp((string) $value, (string) $s[$key], 18), "{$label} {$key}: expected {$value}, got {$s[$key]}");
        }
    }
};
$project = function ($a, string $status = 'active', ?array $owner = null): int {
    $a->as($a->admin);
    $id = (int) ProjectsLite::service($a)->create($a->admin, ['name' => 'LiteTest S3B project ' . bin2hex(random_bytes(2))])['id'];
    $a->pdo->prepare('UPDATE projects SET status = ?, owner_id = ? WHERE id = ?')->execute([$status, (int) ($owner ?? $a->admin)['id'], $id]);

    return $id;
};
$contract = function ($a, ?int $currency = null, ?string $value = null) use ($h): int {
    $a->as($a->admin);
    $input = ['title' => 'LiteTest S3B contract', 'currency_id' => (string) ($currency ?? $h->cur[0])] + ($value !== null ? ['value' => $value] : []);
    $id = (int) $a->container()->make(ContractService::class)->create($a->admin, $input)['id'];
    $a->pdo->exec("UPDATE contracts SET status = 'active' WHERE id = {$id}");

    return $id;
};
$docInput = fn (int $currency, string $amount = '1000') => [
    'currency_id' => (string) $currency, 'pricing_mode' => 'tax_exclusive', 'issue_date' => date('Y-m-d'), 'tax_profile_id' => '',
    'items' => [['description' => 'LiteTest S3B line', 'quantity' => '1', 'unit_price' => $amount, 'discount_type' => 'none', 'discount_value' => '0']],
];
/** An invoice through the real service, then given stored totals / status / paid (tax 10% of the total is recorded but included). */
$invoice = function ($a, ?int $currency = null, string $total = '10000', string $status = 'issued', string $paid = '0', ?array $owner = null) use ($h, $k, $docInput): int {
    $a->as($a->admin);
    $id = (int) $k($a)->make(InvoiceService::class)->create($a->admin, $docInput($currency ?? $h->cur[0], $total))['id'];
    $tax = bcdiv($total, '11', 2);
    $a->pdo->prepare('UPDATE invoices SET subtotal = ?, tax_total = ?, grand_total = ?, status = ?, paid_total = ?, owner_id = ?, invoice_number = ? WHERE id = ?')
        ->execute([bcsub($total, $tax, 2), $tax, $total, $status, $paid, (int) ($owner ?? $a->admin)['id'], 'LT3B-' . strtoupper(bin2hex(random_bytes(3))), $id]);

    return $id;
};
$credit = function ($a, int $invoiceId, string $amount, ?int $orgId = null): void {
    $a->pdo->prepare("INSERT INTO credit_notes (organization_id, invoice_id, credit_note_number, issue_date, reason, amount, issued_by_user_id) VALUES (?, ?, ?, CURDATE(), 'LiteTest S3B', ?, ?)")
        ->execute([$orgId ?? $a->orgId, $invoiceId, 'LT-3B-' . bin2hex(random_bytes(4)), $amount, (int) $a->admin['id']]);
};
$attach = fn ($a, int $projectId, int $invoiceId, ?array $user = null) => $svc($a)->attach($user ?? $a->admin, $projectId, $invoiceId);
$userWith = function ($a, array $permissions): array {
    $a->pdo->prepare('INSERT INTO roles (organization_id, `key`, name, is_system) VALUES (?, ?, ?, 0)')
        ->execute([$a->orgId, 'litetest_s3b_' . bin2hex(random_bytes(4)), 'LiteTest S3B']);
    $roleId = (int) $a->pdo->lastInsertId();
    foreach ($permissions as $key => $scope) {
        $a->pdo->prepare('INSERT INTO role_permissions (role_id, permission_key, scope) VALUES (?, ?, ?)')->execute([$roleId, $key, $scope]);
    }

    return array_merge($a->member, ['role_id' => $roleId]);
};
$manager = ['projects.view' => 'organization', 'projects.edit' => 'organization', 'invoices.view' => 'organization', 'invoices.edit' => 'organization'];
$as = function ($a, array $user, callable $fn) {
    $a->as($user);
    try {
        return $fn();
    } finally {
        $a->as($a->admin);
    }
};
$links = fn ($a, int $invoiceId): array => $a->pdo->query("SELECT linked_id FROM finance_document_links WHERE document_type = 'invoice' AND document_id = {$invoiceId} AND linked_type = 'project'")->fetchAll(\PDO::FETCH_COLUMN);
$events = fn ($a, string $subject, int $id, string $type): int => (int) $a->pdo->query("SELECT COUNT(*) FROM timeline_events WHERE subject_type = '{$subject}' AND subject_id = {$id} AND type = '{$type}'")->fetchColumn();
$refused = function (callable $fn, string $field, string $key): void {
    try {
        $fn();
    } catch (ValidationException $e) {
        $en = require dirname(__DIR__, 2) . '/lang/en.php';
        LiteAssert::same($en[$key], $e->errors[$field][0] ?? null, json_encode($e->errors, JSON_UNESCAPED_UNICODE));

        return;
    }
    throw new LiteAssertionFailed('accepted');
};
$en = require dirname(__DIR__, 2) . '/lang/en.php';

$tests = [];

// ---- Revenue source ------------------------------------------------------

$tests['1. an issued, attributed invoice is revenue (gross, net, receivable)'] = function () use ($boot, $h, $project, $invoice, $attach, $panel, $sum, $eq) {
    $a = $boot();
    $p = $project($a);
    $attach($a, $p, $invoice($a, $h->cur[0], '10000'));
    $eq($sum($panel($a, $p), $h->cur[0]), ['gross' => '10000', 'credited' => '0', 'net' => '10000', 'paid' => '0', 'receivable' => '10000', 'refund_due' => '0']);
};

$tests['2–4. draft, cancelled and proforma contribute nothing (drafts listed, not counted)'] = function () use ($boot, $h, $project, $invoice, $attach, $panel, $k, $docInput) {
    $a = $boot();
    $p = $project($a);
    $draft = $invoice($a, $h->cur[0], '5000', 'draft');
    $attach($a, $p, $draft);
    $cancelled = $invoice($a, $h->cur[0], '7000', 'issued');
    $attach($a, $p, $cancelled);
    $a->pdo->exec("UPDATE invoices SET status = 'cancelled' WHERE id = {$cancelled}");
    // A proforma can't be attributed through the UI; even a raw project link on one is ignored.
    $pf = (int) $k($a)->make(ProformaService::class)->create($a->admin, $docInput($h->cur[0], '9000'))['id'];
    $a->pdo->prepare("INSERT INTO finance_document_links (organization_id, document_type, document_id, linked_type, linked_id) VALUES (?, 'proforma', ?, 'project', ?)")->execute([$a->orgId, $pf, $p]);
    // Sharper: an issued invoice that happens to have the proforma's id must not be picked up by that proforma link.
    if ((int) $a->pdo->query("SELECT COUNT(*) FROM invoices WHERE id = {$pf}")->fetchColumn() === 0) {
        $a->pdo->prepare("INSERT INTO invoices (id, organization_id, owner_id, issue_date, currency_id, subtotal, grand_total, status) VALUES (?, ?, ?, CURDATE(), ?, 9000, 9000, 'issued')")
            ->execute([$pf, $a->orgId, (int) $a->admin['id'], $h->cur[0]]);
    }
    $data = $panel($a, $p);
    LiteAssert::same([], $data['summary'], 'no revenue at all');
    LiteAssert::same([$cancelled, $draft], array_column($data['documents'], 'id'), 'draft and cancelled listed, proforma not');
};

$tests['5–7. contract value, an installment without invoice and the contract ↔ project association are not revenue'] = function () use ($boot, $h, $project, $contract, $panel, $k, $invoice) {
    $a = $boot();
    $p = $project($a);
    $c = $contract($a, $h->cur[1], '100000');
    ProjectsLite::service($a)->link($a->admin, $p, 'contract', $c);
    if ((int) $a->pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'finance_installments'")->fetchColumn() === 1) {
        $a->pdo->prepare("INSERT INTO finance_installments (organization_id, linked_type, linked_id, sequence, due_date, amount, currency_id) VALUES (?, 'contract', ?, 1, '2030-01-01', 50000, ?)")->execute([$a->orgId, $c, $h->cur[1]]);
    }
    // An issued invoice of the CONTRACT, not attributed to the project.
    $inv = $invoice($a, $h->cur[1], '20000');
    $a->pdo->prepare("INSERT INTO finance_document_links (organization_id, document_type, document_id, linked_type, linked_id) VALUES (?, 'invoice', ?, 'contract', ?)")->execute([$a->orgId, $inv, $c]);
    LiteAssert::same([], $panel($a, $p)['summary'], 'USD 0: no revenue from contract value, installment, association or the contract\'s invoices');
};

// ---- Credit notes and payments -------------------------------------------

$tests['8–10. credit notes: partial reduces, full → 0, never negative with payments; refund due'] = function () use ($boot, $h, $project, $invoice, $attach, $credit, $panel, $sum, $eq) {
    $a = $boot();
    $p = $project($a);
    $partial = $invoice($a, $h->cur[0], '10000');
    $credit($a, $partial, '2000');
    $attach($a, $p, $partial);
    $eq($sum($panel($a, $p), $h->cur[0]), ['gross' => '10000', 'credited' => '2000', 'net' => '8000', 'receivable' => '8000'], 'partial');

    $q = $project($a);
    $full = $invoice($a, $h->cur[0], '10000', 'issued', '4000');
    $credit($a, $full, '10000');
    $attach($a, $q, $full);
    $eq($sum($panel($a, $q), $h->cur[0]), ['gross' => '10000', 'credited' => '10000', 'net' => '0', 'paid' => '4000', 'receivable' => '0', 'refund_due' => '4000'], 'full credit after payment');
};

$tests['11. credit notes follow Finance: only the attributed invoice\'s own credits; other invoices / organizations ignored'] = function () use ($boot, $h, $project, $invoice, $attach, $credit, $panel, $sum, $eq) {
    $a = $boot();
    $p = $project($a);
    $mine = $invoice($a, $h->cur[0], '10000');
    $attach($a, $p, $mine);
    $other = $invoice($a, $h->cur[0], '10000');
    $credit($a, $other, '3000');
    if ($a->otherOrgId !== null) {
        $credit($a, $mine, '999', $a->otherOrgId); // another organization's row pointing at my invoice id
    }
    $eq($sum($panel($a, $p), $h->cur[0]), ['credited' => '0', 'net' => '10000']);
};

$tests['12–13. payments never reduce revenue; they reduce only the receivable (10000 − 2000 credit − 5000 paid)'] = function () use ($boot, $h, $project, $invoice, $attach, $credit, $panel, $sum, $eq) {
    $a = $boot();
    $p = $project($a);
    $inv = $invoice($a, $h->cur[0], '10000', 'issued', '5000');
    $credit($a, $inv, '2000');
    $attach($a, $p, $inv);
    $eq($sum($panel($a, $p), $h->cur[0]), ['gross' => '10000', 'credited' => '2000', 'net' => '8000', 'paid' => '5000', 'receivable' => '3000', 'refund_due' => '0']);
};

// ---- Currency ------------------------------------------------------------

$tests['14–17. currencies: three separate totals, values as stored, no conversion, no combined total'] = function () use ($boot, $h, $project, $invoice, $attach, $credit, $panel, $sum, $eq, $en) {
    $a = $boot();
    $p = $project($a);
    [$c1, $c2, $c3] = $h->cur;
    $attach($a, $p, $invoice($a, $c1, '1000'));
    $attach($a, $p, $invoice($a, $c1, '500'));
    $i2 = $invoice($a, $c2, '200');
    $credit($a, $i2, '50');
    $attach($a, $p, $i2);
    $attach($a, $p, $invoice($a, $c3, '7', 'issued', '7'));
    $data = $panel($a, $p);
    LiteAssert::same(3, count($data['summary']), 'one block per currency');
    $eq($sum($data, $c1), ['gross' => '1500', 'net' => '1500'], 'c1');
    $eq($sum($data, $c2), ['gross' => '200', 'credited' => '50', 'net' => '150'], 'c2');
    $eq($sum($data, $c3), ['gross' => '7', 'paid' => '7', 'receivable' => '0'], 'c3');
    $html = $a->request('GET', "/projects/{$p}")['body'];
    LiteAssert::notContains('1,707', $html, 'no cross-currency sum');
    LiteAssert::notContains('1,857', $html);
    LiteAssert::same(3, substr_count($html, htmlspecialchars($en['finance.project.gross'], ENT_QUOTES)), 'one gross row per currency');
};

// ---- Projects and contracts ----------------------------------------------

$tests['18–21. contracts never route revenue: multi-contract project, multi-project contract, counted once, only by attribution'] = function () use ($boot, $h, $project, $contract, $invoice, $attach, $panel, $sum, $eq) {
    $a = $boot();
    [$p1, $p2] = [$project($a), $project($a)];
    [$cA, $cB] = [$contract($a), $contract($a)];
    $ps = ProjectsLite::service($a);
    foreach ([[$p1, $cA], [$p1, $cB], [$p2, $cA]] as [$p, $c]) {
        $ps->link($a->admin, $p, 'contract', $c);
    }
    $contractLink = function (int $inv, int $c) use ($a): void {
        $a->pdo->prepare("INSERT INTO finance_document_links (organization_id, document_type, document_id, linked_type, linked_id) VALUES (?, 'invoice', ?, 'contract', ?)")->execute([$a->orgId, $inv, $c]);
    };
    $iA = $invoice($a, $h->cur[0], '1000');
    $contractLink($iA, $cA);
    $iB = $invoice($a, $h->cur[0], '300');
    $contractLink($iB, $cB);
    $iUnattributed = $invoice($a, $h->cur[0], '9999');
    $contractLink($iUnattributed, $cA);
    $attach($a, $p1, $iA);
    $attach($a, $p1, $iB);
    $eq($sum($panel($a, $p1), $h->cur[0]), ['gross' => '1300'], 'p1: its two invoices once each, nothing via the contracts');
    LiteAssert::same([], $panel($a, $p2)['summary'], 'p2 shares contract A but has no attributed invoice');
};

// ---- Financial linking ---------------------------------------------------

$tests['22. attach through the route: one link, timeline on project and invoice; idempotent'] = function () use ($boot, $h, $project, $invoice, $links, $events, $attach) {
    $a = $boot();
    $p = $project($a);
    $inv = $invoice($a);
    $r = $a->request('POST', "/finance/projects/{$p}/invoices", [], ['invoice_id' => (string) $inv]);
    LiteAssert::same([302, "/projects/{$p}"], [$r['status'], $r['location']]);
    LiteAssert::same([(string) $p], array_map('strval', $links($a, $inv)));
    LiteAssert::same([1, 1], [$events($a, 'project', $p, 'invoice_attributed'), $events($a, 'invoice', $inv, 'project_attributed')]);
    $attach($a, $p, $inv);
    LiteAssert::same(1, count($links($a, $inv)), 'no duplicate row');
    LiteAssert::same(1, $events($a, 'project', $p, 'invoice_attributed'), 'no duplicate timeline');
};

$tests['23. authorization: needs invoices.edit on the invoice AND projects.edit on the project (route + service, record scope)'] = function () use ($boot, $project, $invoice, $userWith, $manager, $as, $links, $svc) {
    $a = $boot();
    $p = $project($a);
    $inv = $invoice($a);
    $route = fn () => $a->outcome('POST', "/finance/projects/{$p}/invoices", [], ['invoice_id' => (string) $inv]);
    foreach (['invoices.edit', 'projects.edit'] as $missing) {
        $u = $userWith($a, array_diff_key($manager, [$missing => 1]));
        LiteAssert::same('forbidden', $as($a, $u, $route), "without {$missing}");
    }
    foreach (['invoices.edit', 'projects.edit'] as $own) {
        $u = $userWith($a, [$own => 'own'] + $manager);
        LiteAssert::same('forbidden', $as($a, $u, $route), "{$own} own, record not theirs");
        LiteAssert::throws(AuthorizationException::class, fn () => $svc($a)->attach($u, $p, $inv));
    }
    LiteAssert::same([], $links($a, $inv));
    LiteAssert::same('ok', $as($a, $userWith($a, $manager), $route));
    LiteAssert::same(1, count($links($a, $inv)));
};

$tests['24–26. tenant and invalid ids: foreign or unknown invoice / project → 404, nothing written'] = function () use ($boot, $project, $invoice, $svc, $links) {
    $a = $boot();
    $p = $project($a);
    $inv = $invoice($a);
    $before = (int) $a->pdo->query('SELECT COUNT(*) FROM finance_document_links')->fetchColumn();
    LiteAssert::same('notfound', $a->outcome('POST', '/finance/projects/999999999/invoices', [], ['invoice_id' => (string) $inv]));
    LiteAssert::same('notfound', $a->outcome('POST', "/finance/projects/{$p}/invoices", [], ['invoice_id' => '999999999']));
    if ($a->otherOrgId !== null) {
        $foreignProject = ProjectsLite::foreignProject($a);
        LiteAssert::throws(NotFoundException::class, fn () => $svc($a)->attach($a->admin, $foreignProject, $inv));
        $foreignInvoice = $invoice($a);
        $a->pdo->exec("UPDATE invoices SET organization_id = {$a->otherOrgId} WHERE id = {$foreignInvoice}");
        LiteAssert::same('notfound', $a->outcome('POST', "/finance/projects/{$p}/invoices", [], ['invoice_id' => (string) $foreignInvoice]));
        LiteAssert::same('notfound', $a->outcome('POST', "/finance/projects/{$p}/invoices/{$foreignInvoice}/detach"));
    }
    LiteAssert::same($before, (int) $a->pdo->query('SELECT COUNT(*) FROM finance_document_links')->fetchColumn());
};

$tests['27. one project per invoice: no move to another project; the UNIQUE key is still there'] = function () use ($boot, $project, $invoice, $attach, $refused, $links, $svc) {
    $a = $boot();
    [$p1, $p2] = [$project($a), $project($a)];
    $inv = $invoice($a);
    $attach($a, $p1, $inv);
    $refused(fn () => $svc($a)->attach($a->admin, $p2, $inv), 'invoice', 'finance.project.error.already_attributed');
    LiteAssert::same([(string) $p1], array_map('strval', $links($a, $inv)));
    try {
        (new FinanceDocumentLinkRepository())->create('invoice', $inv, 'project', $p2, 'dup', null);
        throw new LiteAssertionFailed('second project link accepted');
    } catch (\PDOException $e) {
        LiteAssert::same(1062, $e->errorInfo[1] ?? null);
    }
};

$tests['attach rules: cancelled invoice and completed / cancelled project refused; issued and draft allowed'] = function () use ($boot, $h, $project, $invoice, $attach, $refused, $svc, $links) {
    $a = $boot();
    $cancelled = $invoice($a, null, '100', 'cancelled');
    $refused(fn () => $svc($a)->attach($a->admin, $project($a), $cancelled), 'invoice', 'finance.project.error.invoice_cancelled');
    foreach (['completed', 'cancelled'] as $status) {
        $refused(fn () => $svc($a)->attach($a->admin, $project($a, $status), $invoice($a)), 'project', 'finance.project.error.closed_project');
    }
    foreach (['draft', 'planned', 'active', 'on_hold'] as $status) {
        $inv = $invoice($a, null, '100', 'draft');
        $attach($a, $project($a, $status), $inv);
        LiteAssert::same(1, count($links($a, $inv)), $status);
    }
};

$tests['detach: draft only (issued keeps its project); timeline; route and CSRF'] = function () use ($boot, $project, $invoice, $attach, $refused, $svc, $links, $events) {
    $a = $boot();
    $p = $project($a);
    $draft = $invoice($a, null, '100', 'draft');
    $attach($a, $p, $draft);
    LiteAssert::same('csrf', $a->outcome('POST', "/finance/projects/{$p}/invoices/{$draft}/detach", [], ['_csrf' => 'bad']));
    LiteAssert::same('csrf', $a->outcome('POST', "/finance/projects/{$p}/invoices", [], ['invoice_id' => (string) $draft, '_csrf' => 'bad']));
    $r = $a->request('POST', "/finance/projects/{$p}/invoices/{$draft}/detach");
    LiteAssert::same(302, $r['status']);
    LiteAssert::same([], $links($a, $draft));
    LiteAssert::same([1, 1], [$events($a, 'project', $p, 'invoice_detached'), $events($a, 'invoice', $draft, 'project_detached')]);

    $issued = $invoice($a);
    $attach($a, $p, $issued);
    $refused(fn () => $svc($a)->detach($a->admin, $p, $issued), 'invoice', 'finance.project.error.frozen');
    LiteAssert::same(1, count($links($a, $issued)));
    LiteAssert::throws(NotFoundException::class, fn () => $svc($a)->detach($a->admin, $project($a), $issued), 'not attributed to that project');
};

// ---- Permissions / visibility --------------------------------------------

$tests['28–29. user sees the project but not the invoice: nothing about it (number, amount, status, credits) in data or page'] = function () use ($boot, $h, $project, $invoice, $attach, $credit, $userWith, $panel, $as) {
    $a = $boot();
    $p = $project($a);
    $inv = $invoice($a, $h->cur[0], '43210', 'issued', '1234');
    $credit($a, $inv, '8137');
    $attach($a, $p, $inv);
    $number = (string) $a->pdo->query("SELECT invoice_number FROM invoices WHERE id = {$inv}")->fetchColumn();
    $ownInvoices = $userWith($a, ['projects.view' => 'organization', 'invoices.view' => 'own', 'credit_notes.view' => 'organization']);
    $data = $panel($a, $p, $ownInvoices);
    LiteAssert::same([[], []], [$data['summary'], $data['documents']]);
    $html = $as($a, $ownInvoices, fn () => $a->request('GET', "/projects/{$p}")['body']);
    foreach ([$number, '43,210', '1,234', '8,137', '8137', '/finance/invoices/' . $inv] as $secret) {
        LiteAssert::notContains($secret, $html, "not disclosed: {$secret}");
    }
    $noInvoices = $userWith($a, ['projects.view' => 'organization']);
    LiteAssert::notContains('finance/projects/', $as($a, $noInvoices, fn () => $a->request('GET', "/projects/{$p}")['body']), 'no panel without invoices.view');
};

$tests['credit_notes.view: without it the panel shows ONLY Gross and Paid — no Credited, Net revenue, Receivable or Refund due, in data or page, and nothing that derives them'] = function () use ($boot, $h, $project, $invoice, $attach, $credit, $userWith, $panel, $sum, $eq, $as, $en) {
    $a = $boot();
    $p = $project($a);
    // A: overpaid after a credit (refund due); B: receivable still positive.
    $invA = $invoice($a, $h->cur[0], '10000', 'issued', '9500');
    $credit($a, $invA, '7713');
    $invB = $invoice($a, $h->cur[0], '6000', 'issued', '2000');
    $credit($a, $invB, '1437');
    $attach($a, $p, $invA);
    $attach($a, $p, $invB);
    // credited 9,150 · net 6,850 · receivable 2,563 (B only) · refund due 7,213 (A only)
    $viewer = $userWith($a, ['projects.view' => 'organization', 'invoices.view' => 'organization']);
    $data = $panel($a, $p, $viewer);
    $eq($sum($data, $h->cur[0]), ['gross' => '16000', 'paid' => '11500', 'receivable' => null, 'credited' => null, 'net' => null, 'refund_due' => null], 'no credit view');
    LiteAssert::false($data['show_credits']);
    foreach ($data['documents'] as $d) {
        LiteAssert::same(['id', 'number', 'issue_date', 'status', 'amount', 'currency_id'], array_keys($d), 'documents carry no paid / credit / outstanding field');
    }
    $section = function (string $html): string {
        $start = strpos($html, 'data-related-panel="finance.project"');
        LiteAssert::true($start !== false, 'panel rendered');

        return substr($html, $start, strpos($html, '</section>', $start) - $start);
    };
    $panelHtml = $section($as($a, $viewer, fn () => $a->request('GET', "/projects/{$p}")['body']));
    foreach (['9,150', '6,850', '2,563', '7,213', '7,713', '1,437', $en['finance.credited'], $en['finance.project.net_revenue'], $en['finance.balance'], $en['finance.refund_due']] as $secret) {
        LiteAssert::notContains($secret, $panelHtml, "not disclosed: {$secret}");
    }
    LiteAssert::contains('16,000', $panelHtml, 'gross shown');
    LiteAssert::contains('11,500', $panelHtml, 'paid shown');
    LiteAssert::same(2, substr_count($panelHtml, '<div class="fact">'), 'exactly two facts: Gross and Paid');
    // Gross − Paid (4,500) is neither the credit (9,150) nor anything that yields it without a hidden figure.
    LiteAssert::notContains('4,500', $panelHtml);

    $adminHtml = $section($a->request('GET', "/projects/{$p}")['body']);
    foreach (['16,000', '11,500', '9,150', '6,850', '2,563', '7,213', $en['finance.credited'], $en['finance.project.net_revenue'], $en['finance.balance'], $en['finance.refund_due']] as $shown) {
        LiteAssert::contains($shown, $adminHtml, "admin sees {$shown}");
    }
    $eq($sum($panel($a, $p), $h->cur[0]), ['gross' => '16000', 'credited' => '9150', 'net' => '6850', 'paid' => '11500', 'receivable' => '2563', 'refund_due' => '7213'], 'admin (credit view): unchanged figures');
};

$tests['30. tenant: another organization\'s attribution row on my project / invoice is never counted'] = function () use ($boot, $h, $project, $invoice, $panel) {
    $a = $boot();
    if ($a->otherOrgId === null) {
        throw new LiteSkip('only one organization');
    }
    $p = $project($a);
    $inv = $invoice($a, $h->cur[0], '5555');
    $a->pdo->prepare("INSERT INTO finance_document_links (organization_id, document_type, document_id, linked_type, linked_id) VALUES (?, 'invoice', ?, 'project', ?)")->execute([$a->otherOrgId, $inv, $p]);
    LiteAssert::same([[], []], [$panel($a, $p)['summary'], $panel($a, $p)['documents']]);
};

// ---- Finance isolation ---------------------------------------------------

$tests['31–35. attaching / detaching changes no Finance figure: billing summary, invoices, payments, credit notes, installments'] = function () use ($boot, $h, $project, $contract, $invoice, $credit, $attach, $svc, $k, $docInput, $engine) {
    $a = $boot();
    $c = $contract($a);
    $installments = new InstallmentService(new InstallmentRepository(), new FinanceDocumentLinkRepository(), new ProformaRepository(), new InvoiceRepository(), new CreditNoteRepository(),
        $k($a)->make(ProformaService::class), $k($a)->make(InvoiceService::class), $engine($a),
        $k($a)->make(\App\Application\Services\TimelineService::class), $k($a)->make(\App\Infrastructure\Records\RecordTypeRegistry::class));
    $billing = new ContractBillingService($k($a)->make(ProformaService::class), $k($a)->make(InvoiceService::class), new FinanceDocumentLinkRepository(),
        $k($a)->make(\App\Application\Services\TimelineService::class), $k($a)->make(\App\Infrastructure\Records\RecordTypeRegistry::class), installments: $installments);
    $issued = (int) $billing->createLinked($a->admin, 'invoice', $docInput($h->cur[0]), 'contract', $c)['id'];
    $a->pdo->exec("UPDATE invoices SET status = 'issued', grand_total = 1000, paid_total = 300 WHERE id = {$issued}");
    $credit($a, $issued, '100');
    $draft = (int) $billing->createLinked($a->admin, 'invoice', $docInput($h->cur[0]), 'contract', $c)['id'];
    $hasInstallments = (int) $a->pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'finance_installments'")->fetchColumn() === 1;
    if ($hasInstallments) {
        $installments->create($a->admin, $c, ['due_date' => '2030-01-01', 'amount' => '400']);
    }
    $can = fn (string $key): bool => $engine($a)->can($a->admin, $key);
    $snapshot = fn () => [
        $billing->panelFor($a->admin, $c, $can),
        $hasInstallments ? $installments->scheduleFor($a->admin, $installments->contract($a->admin, $c), $can) : null,
        $a->pdo->query('SELECT id, status, subtotal, tax_total, grand_total, paid_total, currency_id FROM invoices ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC),
        $a->pdo->query('SELECT COUNT(*), COALESCE(SUM(amount), 0) FROM payments')->fetch(\PDO::FETCH_NUM),
        $a->pdo->query('SELECT COUNT(*), COALESCE(SUM(amount), 0) FROM credit_notes')->fetch(\PDO::FETCH_NUM),
        $a->pdo->query("SELECT * FROM finance_document_links WHERE linked_type <> 'project' ORDER BY id")->fetchAll(\PDO::FETCH_ASSOC),
        $hasInstallments ? $a->pdo->query('SELECT * FROM finance_installments ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC) : null,
    ];
    $before = $snapshot();
    $p = $project($a);
    $attach($a, $p, $issued);
    $attach($a, $p, $draft);
    LiteAssert::same($before, $snapshot(), 'after attach');
    $svc($a)->detach($a->admin, $p, $draft);
    LiteAssert::same($before, $snapshot(), 'after detach');
};

$tests['panel: attach list offers only unattributed, non-cancelled invoices the user may edit; closed project offers none; five languages'] = function () use ($boot, $project, $invoice, $attach, $panel, $en) {
    $a = $boot();
    $p = $project($a);
    $free = $invoice($a, null, '100', 'draft');
    $taken = $invoice($a);
    $attach($a, $project($a), $taken);
    $cancelled = $invoice($a, null, '100', 'cancelled');
    $ids = array_map(fn ($r) => (int) $r['id'], $panel($a, $p)['candidates']);
    LiteAssert::true(in_array($free, $ids, true) && !in_array($taken, $ids, true) && !in_array($cancelled, $ids, true), json_encode($ids));
    LiteAssert::false($panel($a, $project($a, 'completed'))['can_attach']);
    try {
        foreach (['en', 'fa', 'ar', 'tr', 'fr'] as $locale) {
            $a->locale($locale);
            $lang = require dirname(__DIR__, 2) . "/lang/{$locale}.php";
            $html = $a->request('GET', "/projects/{$p}")['body'];
            LiteAssert::contains(htmlspecialchars($lang['finance.project.title'], ENT_QUOTES), $html, $locale);
            LiteAssert::notContains('finance.project.', $html, "{$locale}: raw key");
        }
    } finally {
        $a->locale('en');
    }
};

$tests['rollback leaves no test data behind'] = function () use ($boot) {
    $a = $boot();
    $a->rollBack();
    LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM projects WHERE name LIKE 'LiteTest S3B%'")->fetchColumn());
    LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM finance_document_links WHERE linked_type = 'project'")->fetchColumn());
    LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM roles WHERE `key` LIKE 'litetest_s3b_%'")->fetchColumn());
};

return $tests;
