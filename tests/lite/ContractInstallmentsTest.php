<?php

declare(strict_types=1);

/**
 * Stage 2 — contract installment schedule (docs/project-finance-design.md).
 *
 * An installment is a plan (amount + due date), never revenue, receivable or
 * payment. Status is derived from its one active document. Contract status:
 * create / edit only while active; documents while active or expired;
 * nothing new once terminated; void (reason, no active document) any time.
 * Currency: the contract's, else the schedule's first, never mixed.
 * Managing needs contracts.edit on the contract; documents need their own
 * create permission. A linked document the user may not open is shown only
 * as "restricted". Proforma → invoice conversion is locked and carries the
 * installment link.
 *
 * Issue / payments / credit notes are stored values (their services open
 * their own transaction, which can't nest here). crm_local only, one
 * transaction, rolled back.
 */

require_once __DIR__ . '/Support/LocalApp.php';

use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use Modules\Finance\CreditNoteRepository;
use Modules\Finance\FinanceDocumentLinkRepository;
use Modules\Finance\InstallmentRepository;
use Modules\Finance\InvoiceRepository;
use Modules\Finance\ProformaRepository;
use Modules\Finance\Services\ContractBillingService;
use Modules\Finance\Services\InstallmentService;
use Modules\Finance\Services\InvoiceService;
use Modules\Finance\Services\ProformaService;
use Tests\Lite\Support\LocalApp;

$h = new \stdClass();
$h->app = null;
$h->reason = '';

$boot = function () use ($h): LocalApp {
    if ($h->app !== null) {
        return $h->app;
    }
    if ($h->reason !== '') {
        throw new LiteSkip($h->reason);
    }
    try {
        $a = LocalApp::boot();
    } catch (\Throwable $e) {
        $h->reason = 'local database unavailable: ' . $e->getMessage();
        throw new LiteSkip($h->reason);
    }
    if ($a->member === null || (int) $a->pdo->query('SELECT COUNT(*) FROM currencies WHERE is_active = 1')->fetchColumn() < 2) {
        $a->rollBack();
        $h->reason = 'needs a second active user and two active currencies';
        throw new LiteSkip($h->reason);
    }
    if ($a->pdo->query("SHOW TABLES LIKE 'finance_installments'")->fetchColumn() === false) {
        $a->rollBack();
        $h->reason = 'Finance migration 0017 not applied to crm_local';
        throw new LiteSkip($h->reason);
    }
    $a->activateContracts();
    $finance = (int) $a->pdo->query("SELECT id FROM modules WHERE `key` = 'finance'")->fetchColumn();
    $a->pdo->prepare("UPDATE modules SET status = 'active' WHERE id = ?")->execute([$finance]);
    $a->pdo->prepare('DELETE FROM organization_modules WHERE organization_id = ? AND module_id = ?')->execute([$a->orgId, $finance]);
    $a->pdo->prepare('INSERT INTO organization_modules (organization_id, module_id, enabled) VALUES (?, ?, 1)')->execute([$a->orgId, $finance]);
    $a->pdo->prepare("DELETE FROM financial_periods WHERE organization_id = ? AND status = 'closed'")->execute([$a->orgId]);
    $a->as($a->admin);
    $a->locale('en');
    $h->cur = array_map('intval', $a->pdo->query('SELECT id FROM currencies WHERE is_active = 1 ORDER BY id LIMIT 2')->fetchAll(\PDO::FETCH_COLUMN));
    $h->app = $a;

    return $a;
};

$c = fn (LocalApp $a) => $a->container();
/** Built with the same arguments FinanceModule passes (the route tests cover the real wiring). */
$svc = fn (LocalApp $a): InstallmentService => new InstallmentService(
    new InstallmentRepository(),
    new FinanceDocumentLinkRepository(),
    new ProformaRepository(),
    new InvoiceRepository(),
    new CreditNoteRepository(),
    $c($a)->make(ProformaService::class),
    $c($a)->make(InvoiceService::class),
    $c($a)->make(\App\Infrastructure\Authorization\AuthorizationEngine::class),
    $c($a)->make(\App\Application\Services\TimelineService::class),
    $c($a)->make(\App\Infrastructure\Records\RecordTypeRegistry::class),
);
$billing = fn (LocalApp $a): ContractBillingService => new ContractBillingService(
    $c($a)->make(ProformaService::class),
    $c($a)->make(InvoiceService::class),
    new FinanceDocumentLinkRepository(),
    $c($a)->make(\App\Application\Services\TimelineService::class),
    $c($a)->make(\App\Infrastructure\Records\RecordTypeRegistry::class),
    installments: $svc($a),
);
$engine = fn (LocalApp $a) => $c($a)->make(\App\Infrastructure\Authorization\AuthorizationEngine::class);
$canFor = fn (LocalApp $a, array $user) => fn (string $key): bool => $engine($a)->can($user, $key);

/** A contract of $owner (admin by default) in the given stored status. */
$contract = function (LocalApp $a, string $status = 'active', ?int $currency = -1, ?string $value = null, ?string $endDate = null, ?array $owner = null) use ($h, $c): int {
    $a->as($a->admin);
    $currency = $currency === -1 ? $h->cur[0] : $currency;
    $input = ['title' => 'LiteTest S2 contract'] + ($currency !== null ? ['currency_id' => (string) $currency] : []) + ($value !== null ? ['value' => $value] : []);
    $id = (int) $c($a)->make(\Modules\Contracts\Services\ContractService::class)->create($a->admin, $input)['id'];
    $a->pdo->prepare('UPDATE contracts SET status = ?, end_date = ?, owner_id = ? WHERE id = ?')
        ->execute([$status, $endDate, (int) ($owner ?? $a->admin)['id'], $id]);

    return $id;
};
$add = function (LocalApp $a, int $contractId, string $amount = '1000', array $extra = [], ?array $user = null) use ($svc): int {
    return (int) $svc($a)->create($user ?? $a->admin, $contractId, $extra + ['due_date' => '2030-01-15', 'amount' => $amount])['id'];
};
$docInput = fn (int $currency, string $amount = '1000') => [
    'currency_id' => (string) $currency, 'pricing_mode' => 'tax_exclusive', 'issue_date' => date('Y-m-d'), 'tax_profile_id' => '',
    'items' => [['description' => 'LiteTest S2 line', 'quantity' => '1', 'unit_price' => $amount, 'discount_type' => 'none', 'discount_value' => '0']],
];
/** A document from installment $id through the real path; then given a stored status / paid / total. */
$docFrom = function (LocalApp $a, int $id, string $type = 'invoice', ?int $currency = null, string $status = 'draft', string $paid = '0', string $total = '1000', ?array $user = null) use ($h, $billing, $docInput): int {
    $doc = $billing($a)->createLinked($user ?? $a->admin, $type, $docInput($currency ?? $h->cur[0], $total), 'installment', $id);
    if ($type === 'invoice') {
        $a->pdo->prepare('UPDATE invoices SET subtotal = ?, tax_total = 0, grand_total = ?, status = ?, paid_total = ? WHERE id = ?')
            ->execute([$total, $total, $status, $paid, (int) $doc['id']]);
    }

    return (int) $doc['id'];
};
$credit = function (LocalApp $a, int $invoiceId, string $amount): void {
    $a->pdo->prepare("INSERT INTO credit_notes (organization_id, invoice_id, credit_note_number, issue_date, reason, amount, issued_by_user_id) VALUES (?, ?, ?, CURDATE(), 'LiteTest S2', ?, ?)")
        ->execute([$a->orgId, $invoiceId, 'LT-S2-' . bin2hex(random_bytes(4)), $amount, (int) $a->admin['id']]);
};
/** The member acting with a temporary role holding exactly $permissions ([key => scope]). */
$userWith = function (LocalApp $a, array $permissions): array {
    $a->pdo->prepare('INSERT INTO roles (organization_id, `key`, name, is_system) VALUES (?, ?, ?, 0)')
        ->execute([$a->orgId, 'litetest_s2_' . bin2hex(random_bytes(4)), 'LiteTest S2']);
    $roleId = (int) $a->pdo->lastInsertId();
    foreach ($permissions as $key => $scope) {
        $a->pdo->prepare('INSERT INTO role_permissions (role_id, permission_key, scope) VALUES (?, ?, ?)')->execute([$roleId, $key, $scope]);
    }

    return array_merge($a->member, ['role_id' => $roleId]);
};
$row = fn (LocalApp $a, int $id): ?array => $a->pdo->query("SELECT * FROM finance_installments WHERE id = {$id}")->fetch(\PDO::FETCH_ASSOC) ?: null;
$count = fn (LocalApp $a, string $sql): int => (int) $a->pdo->query($sql)->fetchColumn();
/** Money-side fingerprint: no installment action may change any of these. */
$money = fn (LocalApp $a): array => [
    $count($a, 'SELECT COUNT(*) FROM invoices'), $count($a, 'SELECT COUNT(*) FROM proformas'), $count($a, 'SELECT COUNT(*) FROM payments'),
    $count($a, 'SELECT COUNT(*) FROM credit_notes'), $count($a, 'SELECT COUNT(*) FROM finance_document_links'),
    (string) $a->pdo->query('SELECT COALESCE(SUM(grand_total), 0) + COALESCE(SUM(paid_total), 0) FROM invoices')->fetchColumn(),
];
$timeline = fn (LocalApp $a, int $contractId, string $type): array => $a->pdo->query("SELECT * FROM timeline_events WHERE subject_type = 'contract' AND subject_id = {$contractId} AND type = '{$type}' ORDER BY id")->fetchAll(\PDO::FETCH_ASSOC);
/** Asserts $fn throws ValidationException on $field, and that the installment table and money side did not change. */
$refused = function (LocalApp $a, callable $fn, string $field, ?string $key = null) use ($money, $count): void {
    $before = [$money($a), $count($a, 'SELECT COUNT(*) FROM finance_installments'), $count($a, 'SELECT COALESCE(SUM(UNIX_TIMESTAMP(updated_at)), 0) FROM finance_installments')];
    try {
        $fn();
        throw new LiteAssertionFailed('accepted');
    } catch (ValidationException $e) {
        LiteAssert::true(isset($e->errors[$field]), "expected {$field}, got " . json_encode($e->errors, JSON_UNESCAPED_UNICODE));
        if ($key !== null) {
            $en = require dirname(__DIR__, 2) . '/lang/en.php';
            LiteAssert::same($en[$key], $e->errors[$field][0] ?? null, 'message');
        }
    }
    LiteAssert::same($before, [$money($a), $count($a, 'SELECT COUNT(*) FROM finance_installments'), $count($a, 'SELECT COALESCE(SUM(UNIX_TIMESTAMP(updated_at)), 0) FROM finance_installments')], 'nothing written');
};
$schedule = function (LocalApp $a, int $contractId, ?array $user = null) use ($svc, $canFor): array {
    $user ??= $a->admin;
    $contract = $svc($a)->contract($user, $contractId);
    LiteAssert::true($contract !== null, 'contract viewable');

    return $svc($a)->scheduleFor($user, $contract, $canFor($a, $user));
};
$rowOf = function (array $schedule, int $id): array {
    foreach ($schedule['rows'] as $r) {
        if ($r['id'] === $id) {
            return $r;
        }
    }
    throw new LiteAssertionFailed("installment {$id} not in schedule");
};
$en = require dirname(__DIR__, 2) . '/lang/en.php';

$tests = [];

$tests['1. create on an active contract: contract currency, sequence 1, 2…, timeline; no financial document or money change'] = function () use ($boot, $h, $contract, $add, $row, $money, $timeline) {
    $a = $boot();
    $k = $contract($a);
    $before = $money($a);
    $one = $add($a, $k, '1,250.50', ['description' => 'Down payment']);
    $two = $add($a, $k, '500');
    LiteAssert::same($before, $money($a), 'no invoice / proforma / payment / credit / link / amount written');
    LiteAssert::same([1, 2], [(int) $row($a, $one)['sequence'], (int) $row($a, $two)['sequence']]);
    LiteAssert::same($h->cur[0], (int) $row($a, $one)['currency_id'], 'contract currency');
    LiteAssert::same(0, bccomp('1250.5', (string) $row($a, $one)['amount'], 18));
    LiteAssert::same('Down payment', $row($a, $one)['description']);
    LiteAssert::same(2, count($timeline($a, $k, 'installment_created')));
};

$tests['2. create refused unless the contract is active (draft, pending, expired, terminated, cancelled)'] = function () use ($boot, $contract, $svc, $refused) {
    $a = $boot();
    $cases = [
        $contract($a, 'draft'), $contract($a, 'pending_approval'), $contract($a, 'terminated'), $contract($a, 'cancelled'),
        $contract($a, 'active', -1, null, date('Y-m-d', strtotime('-1 day'))), // expired, computed
    ];
    foreach ($cases as $k) {
        $refused($a, fn () => $svc($a)->create($a->admin, $k, ['due_date' => '2030-01-01', 'amount' => '10']), 'contract', 'finance.installment.error.contract_not_active');
    }
};

$tests['3. field validation: amount > 0 and numeric, real due date, description ≤ 255'] = function () use ($boot, $contract, $svc, $refused) {
    $a = $boot();
    $k = $contract($a);
    foreach (['0', '-5', 'abc', '', '1.2.3'] as $bad) {
        $refused($a, fn () => $svc($a)->create($a->admin, $k, ['due_date' => '2030-01-01', 'amount' => $bad]), 'amount', 'finance.installment.error.invalid_amount');
    }
    foreach (['', '2030-02-30', '15/01/2030', 'x'] as $bad) {
        $refused($a, fn () => $svc($a)->create($a->admin, $k, ['due_date' => $bad, 'amount' => '10']), 'due_date', 'finance.installment.error.invalid_due_date');
    }
    $refused($a, fn () => $svc($a)->create($a->admin, $k, ['due_date' => '2030-01-01', 'amount' => '10', 'description' => str_repeat('x', 256)]), 'description');
};

$tests['4. currency: contract currency enforced; without one the first installment fixes it; void-all frees it; inactive refused'] = function () use ($boot, $h, $contract, $add, $svc, $refused, $row) {
    $a = $boot();
    [$c1, $c2] = $h->cur;
    $withCurrency = $contract($a, 'active', $c1);
    $refused($a, fn () => $svc($a)->create($a->admin, $withCurrency, ['due_date' => '2030-01-01', 'amount' => '10', 'currency_id' => (string) $c2]), 'currency_id', 'finance.installment.error.currency_mismatch');

    $none = $contract($a, 'active', null);
    $refused($a, fn () => $svc($a)->create($a->admin, $none, ['due_date' => '2030-01-01', 'amount' => '10']), 'currency_id'); // must pick one
    $first = $add($a, $none, '10', ['currency_id' => (string) $c2]);
    LiteAssert::same($c2, (int) $row($a, $first)['currency_id']);
    $second = $add($a, $none, '20'); // no currency sent → the schedule's
    LiteAssert::same($c2, (int) $row($a, $second)['currency_id']);
    $refused($a, fn () => $svc($a)->create($a->admin, $none, ['due_date' => '2030-01-01', 'amount' => '10', 'currency_id' => (string) $c1]), 'currency_id', 'finance.installment.error.currency_mismatch');

    $svc($a)->void($a->admin, $first, 'wrong currency');
    $svc($a)->void($a->admin, $second, 'wrong currency');
    $third = $add($a, $none, '30', ['currency_id' => (string) $c1]);
    LiteAssert::same($c1, (int) $row($a, $third)['currency_id'], 'all void → a new schedule currency');
    LiteAssert::same(3, (int) $row($a, $third)['sequence'], 'void installments keep their numbers');

    $inactive = (int) $a->pdo->query('SELECT id FROM currencies WHERE is_active = 0 ORDER BY id LIMIT 1')->fetchColumn();
    if ($inactive > 0) {
        $fresh = $contract($a, 'active', null);
        $refused($a, fn () => $svc($a)->create($a->admin, $fresh, ['due_date' => '2030-01-01', 'amount' => '10', 'currency_id' => (string) $inactive]), 'currency_id');
    }
};

$tests['5. permissions: no contracts.edit → 403 (route and service); own scope only on own contracts; no contracts.view → 404'] = function () use ($boot, $contract, $svc, $userWith, $count) {
    $a = $boot();
    $adminContract = $contract($a);
    $viewer = $userWith($a, ['contracts.view' => 'organization', 'invoices.view' => 'organization', 'invoices.create' => 'organization']);
    $a->as($viewer);
    LiteAssert::same('forbidden', $a->outcome('POST', "/finance/contracts/{$adminContract}/installments", [], ['due_date' => '2030-01-01', 'amount' => '10']), 'route RBAC');
    $a->as($a->admin);
    LiteAssert::throws(AuthorizationException::class, fn () => $svc($a)->create($viewer, $adminContract, ['due_date' => '2030-01-01', 'amount' => '10']));

    $ownEditor = $userWith($a, ['contracts.view' => 'organization', 'contracts.edit' => 'own', 'invoices.view' => 'organization']);
    LiteAssert::throws(AuthorizationException::class, fn () => $svc($a)->create($ownEditor, $adminContract, ['due_date' => '2030-01-01', 'amount' => '10']), 'own scope, not theirs');
    $mine = $contract($a, 'active', -1, null, null, $ownEditor);
    $svc($a)->create($ownEditor, $mine, ['due_date' => '2030-01-01', 'amount' => '10']);

    $blind = $userWith($a, ['contracts.edit' => 'organization', 'invoices.view' => 'organization']);
    $before = $count($a, 'SELECT COUNT(*) FROM finance_installments');
    LiteAssert::throws(NotFoundException::class, fn () => $svc($a)->create($blind, $adminContract, ['due_date' => '2030-01-01', 'amount' => '10']), 'contract not viewable');
    LiteAssert::same($before, $count($a, 'SELECT COUNT(*) FROM finance_installments'));
};

$tests['6. tenant isolation: another organization\'s installment / contract ids → 404, nothing written'] = function () use ($boot, $contract, $add, $svc, $money, $count, $schedule) {
    $a = $boot();
    if ($a->otherOrgId === null) {
        throw new LiteSkip('only one organization');
    }
    $k = $contract($a);
    $i = $add($a, $k);
    // Move the installment AND its contract to the other organization.
    $a->pdo->exec("UPDATE finance_installments SET organization_id = {$a->otherOrgId} WHERE id = {$i}");
    $a->pdo->exec("UPDATE contracts SET organization_id = {$a->otherOrgId} WHERE id = {$k}");
    $before = [$money($a), $count($a, 'SELECT COUNT(*) FROM finance_installments')];
    LiteAssert::same('notfound', $a->outcome('POST', "/finance/installments/{$i}", [], ['due_date' => '2030-01-01', 'amount' => '1']));
    LiteAssert::same('notfound', $a->outcome('POST', "/finance/installments/{$i}/void", [], ['reason' => 'x']));
    LiteAssert::same('notfound', $a->outcome('GET', "/finance/installments/{$i}/edit"));
    LiteAssert::same('notfound', $a->outcome('POST', "/finance/contracts/{$k}/installments", [], ['due_date' => '2030-01-01', 'amount' => '1']));
    LiteAssert::same('notfound', $a->outcome('GET', '/finance/invoices/create', ['from_installment' => (string) $i]));
    LiteAssert::throws(NotFoundException::class, fn () => $svc($a)->billable($a->admin, $i));
    LiteAssert::same($before, [$money($a), $count($a, 'SELECT COUNT(*) FROM finance_installments')], 'nothing written');

    // Sharper: another organization's installment row that points at a contract of MY
    // organization. Only the installment's own tenant scope can stop this one.
    $mine = $contract($a);
    $foreign = $add($a, $mine, '55');
    $a->pdo->exec("UPDATE finance_installments SET organization_id = {$a->otherOrgId} WHERE id = {$foreign}");
    $snapshot = $a->pdo->query("SELECT * FROM finance_installments WHERE id = {$foreign}")->fetch(\PDO::FETCH_ASSOC);
    LiteAssert::same('notfound', $a->outcome('POST', "/finance/installments/{$foreign}", [], ['due_date' => '2030-01-01', 'amount' => '1']));
    LiteAssert::same('notfound', $a->outcome('POST', "/finance/installments/{$foreign}/void", [], ['reason' => 'x']));
    LiteAssert::same('notfound', $a->outcome('GET', '/finance/invoices/create', ['from_installment' => (string) $foreign]));
    LiteAssert::same($snapshot, $a->pdo->query("SELECT * FROM finance_installments WHERE id = {$foreign}")->fetch(\PDO::FETCH_ASSOC), 'foreign row untouched');
    LiteAssert::same([], array_filter($schedule($a, $mine)['rows'], fn (array $r) => $r['id'] === $foreign), 'not listed on my contract');
};

$tests['7. CSRF on every installment POST'] = function () use ($boot, $contract, $add) {
    $a = $boot();
    $k = $contract($a);
    $i = $add($a, $k);
    LiteAssert::same('csrf', $a->outcome('POST', "/finance/contracts/{$k}/installments", [], ['due_date' => '2030-01-01', 'amount' => '1', '_csrf' => 'bad']));
    LiteAssert::same('csrf', $a->outcome('POST', "/finance/installments/{$i}", [], ['due_date' => '2030-01-01', 'amount' => '1', '_csrf' => 'bad']));
    LiteAssert::same('csrf', $a->outcome('POST', "/finance/installments/{$i}/void", [], ['reason' => 'x', '_csrf' => 'bad']));
};

$tests['8. edit: only while active, open (not void, no active document); timeline'] = function () use ($boot, $contract, $add, $svc, $refused, $row, $docFrom, $timeline) {
    $a = $boot();
    $k = $contract($a);
    $i = $add($a, $k);
    $svc($a)->update($a->admin, $i, ['due_date' => '2031-02-03', 'amount' => '777', 'description' => 'moved']);
    LiteAssert::same('2031-02-03', $row($a, $i)['due_date']);
    LiteAssert::same(0, bccomp('777', (string) $row($a, $i)['amount'], 18));
    LiteAssert::same(1, count($timeline($a, $k, 'installment_updated')));

    $withDoc = $add($a, $k);
    $docFrom($a, $withDoc); // a draft invoice is an active document
    $refused($a, fn () => $svc($a)->update($a->admin, $withDoc, ['due_date' => '2031-01-01', 'amount' => '1']), 'installment', 'finance.installment.error.has_active_document');

    $voided = $add($a, $k);
    $svc($a)->void($a->admin, $voided, 'not needed');
    $refused($a, fn () => $svc($a)->update($a->admin, $voided, ['due_date' => '2031-01-01', 'amount' => '1']), 'installment', 'finance.installment.error.voided');

    $later = $add($a, $k);
    foreach (['terminated' => null, 'active' => date('Y-m-d', strtotime('-1 day'))] as $status => $end) {
        $a->pdo->prepare('UPDATE contracts SET status = ?, end_date = ? WHERE id = ?')->execute([$status, $end, $k]);
        $refused($a, fn () => $svc($a)->update($a->admin, $later, ['due_date' => '2031-01-01', 'amount' => '1']), 'contract', 'finance.installment.error.contract_not_active');
    }
};

$tests['9. void: reason required (≤ 450), no active document; allowed on a terminated contract; excluded from totals; timeline'] = function () use ($boot, $contract, $add, $svc, $refused, $row, $docFrom, $timeline, $schedule) {
    $a = $boot();
    $k = $contract($a);
    $i = $add($a, $k, '100');
    $keep = $add($a, $k, '40');
    $refused($a, fn () => $svc($a)->void($a->admin, $i, '   '), 'reason', 'finance.installment.error.reason_required');
    $refused($a, fn () => $svc($a)->void($a->admin, $i, str_repeat('x', 451)), 'reason', 'finance.installment.error.reason_too_long');

    $withDoc = $add($a, $k);
    $docFrom($a, $withDoc, 'proforma');
    $refused($a, fn () => $svc($a)->void($a->admin, $withDoc, 'x'), 'installment', 'finance.installment.error.has_active_document');

    $a->pdo->exec("UPDATE contracts SET status = 'terminated' WHERE id = {$k}");
    $svc($a)->void($a->admin, $i, '  Customer terminated  ');
    $r = $row($a, $i);
    LiteAssert::true($r['voided_at'] !== null);
    LiteAssert::same('Customer terminated', $r['void_reason']);
    LiteAssert::same((int) $a->admin['id'], (int) $r['voided_by_user_id']);
    LiteAssert::same('Installment #1 voided: Customer terminated', $timeline($a, $k, 'installment_voided')[0]['description'] ?? null);
    $refused($a, fn () => $svc($a)->void($a->admin, $i, 'again'), 'installment', 'finance.installment.error.voided');

    $s = $schedule($a, $k);
    LiteAssert::same('void', $s['rows'][0]['status']);
    LiteAssert::same(0, bccomp('1040', $s['totals'][array_key_first($s['totals'])], 18), 'void excluded: 40 + 1000 (the one with a document)');
};

$tests['10. documents from an installment: both links, currency must match, one active document, state rules, nothing written on refusal'] = function () use ($boot, $h, $contract, $add, $svc, $billing, $docInput, $refused, $docFrom, $count, $timeline) {
    $a = $boot();
    $k = $contract($a);
    $i = $add($a, $k);
    $refused($a, fn () => $billing($a)->createLinked($a->admin, 'invoice', $docInput($h->cur[1]), 'installment', $i), 'currency_id', 'finance.installment.error.document_currency');

    $inv = $docFrom($a, $i);
    $links = $a->pdo->query("SELECT linked_type, linked_id FROM finance_document_links WHERE document_type = 'invoice' AND document_id = {$inv} ORDER BY linked_type")->fetchAll(\PDO::FETCH_KEY_PAIR);
    LiteAssert::same(['contract' => (string) $k, 'installment' => (string) $i], array_map('strval', $links));
    LiteAssert::same('Invoice draft created from installment #1', $timeline($a, $k, 'invoice_created')[0]['description'] ?? null);

    foreach (['invoice', 'proforma'] as $type) {
        $refused($a, fn () => $billing($a)->createLinked($a->admin, $type, $docInput($h->cur[0]), 'installment', $i), 'installment', 'finance.installment.error.has_active_document');
    }

    $v = $add($a, $k);
    $svc($a)->void($a->admin, $v, 'x');
    $refused($a, fn () => $billing($a)->createLinked($a->admin, 'invoice', $docInput($h->cur[0]), 'installment', $v), 'installment', 'finance.installment.error.voided');

    $expired = $add($a, $k);
    $a->pdo->prepare('UPDATE contracts SET end_date = ? WHERE id = ?')->execute([date('Y-m-d', strtotime('-1 day')), $k]);
    $docFrom($a, $expired, 'proforma'); // expired: still billable
    $open = $add($a, $contract($a));
    $openContract = (int) $a->pdo->query("SELECT linked_id FROM finance_installments WHERE id = {$open}")->fetchColumn();
    $a->pdo->exec("UPDATE contracts SET status = 'terminated' WHERE id = {$openContract}");
    $refused($a, fn () => $billing($a)->createLinked($a->admin, 'invoice', $docInput($h->cur[0]), 'installment', $open), 'source', 'finance.installment.error.contract_not_billable');
    $refused($a, fn () => $billing($a)->createLinked($a->admin, 'invoice', $docInput($h->cur[0]), 'installment', 999999999), 'source', 'finance.installment.error.unavailable');
};

$tests['11. document permission: the route needs invoices.create / quotes.create; the service refuses without it'] = function () use ($boot, $h, $contract, $add, $billing, $docInput, $userWith) {
    $a = $boot();
    $k = $contract($a);
    $i = $add($a, $k);
    $noCreate = $userWith($a, ['contracts.view' => 'organization', 'invoices.view' => 'organization', 'quotes.view' => 'organization']);
    $a->as($noCreate);
    LiteAssert::same('forbidden', $a->outcome('GET', '/finance/invoices/create', ['from_installment' => (string) $i]));
    LiteAssert::same('forbidden', $a->outcome('POST', '/finance/invoices', [], $docInput($h->cur[0]) + ['source_type' => 'installment', 'source_id' => (string) $i]));
    LiteAssert::same('forbidden', $a->outcome('POST', '/finance/proformas', [], $docInput($h->cur[0]) + ['source_type' => 'installment', 'source_id' => (string) $i]));
    $a->as($a->admin);
    LiteAssert::throws(AuthorizationException::class, fn () => $billing($a)->createLinked($noCreate, 'invoice', $docInput($h->cur[0]), 'installment', $i));
};

$tests['12. routes: prefilled form from an installment; POST creates the linked draft; ineligible → back to the contract; unknown → 404'] = function () use ($boot, $h, $contract, $add, $svc, $docInput, $count) {
    $a = $boot();
    $k = $contract($a);
    $i = $add($a, $k, '2468.5', ['due_date' => '2030-07-01']);
    $form = $a->request('GET', '/finance/invoices/create', ['from_installment' => (string) $i]);
    LiteAssert::same(200, $form['status']);
    LiteAssert::contains('name="source_type" value="installment"', $form['body']);
    LiteAssert::contains('name="source_id" value="' . $i . '"', $form['body']);
    LiteAssert::contains('2468.5', $form['body'], 'amount prefilled');
    LiteAssert::contains('2030-07-01', $form['body'], 'due date prefilled');
    LiteAssert::same(200, $a->request('GET', '/finance/proformas/create', ['from_installment' => (string) $i])['status']);

    $r = $a->request('POST', '/finance/invoices', $docInput($h->cur[0], '2468.5') + ['source_type' => 'installment', 'source_id' => (string) $i]);
    LiteAssert::same(302, $r['status']);
    $invoiceId = (int) basename((string) $r['location']);
    LiteAssert::same(1, $count($a, "SELECT COUNT(*) FROM finance_document_links WHERE document_type = 'invoice' AND document_id = {$invoiceId} AND linked_type = 'installment' AND linked_id = {$i}"));

    $again = $a->request('GET', '/finance/invoices/create', ['from_installment' => (string) $i]);
    LiteAssert::same(302, $again['status'], 'already has an active document → back to the contract');
    LiteAssert::same('/contracts/' . $k, $again['location']);
    LiteAssert::same('notfound', $a->outcome('GET', '/finance/invoices/create', ['from_installment' => '999999999']));
    // Refused POST re-renders the form with the source kept, nothing created.
    $before = $count($a, 'SELECT COUNT(*) FROM invoices');
    $bad = $a->request('POST', '/finance/invoices', $docInput($h->cur[0]) + ['source_type' => 'installment', 'source_id' => (string) $i]);
    LiteAssert::same(422, $bad['status']);
    LiteAssert::same($before, $count($a, 'SELECT COUNT(*) FROM invoices'));
};

$tests['13. derived status: unpaid → issued → partially_paid → paid; fully credited = paid; cancel / delete → unpaid again; overdue flag'] = function () use ($boot, $contract, $add, $docFrom, $credit, $schedule, $rowOf, $c) {
    $a = $boot();
    $k = $contract($a);
    $i = $add($a, $k, '1000', ['due_date' => date('Y-m-d', strtotime('-3 days'))]);
    $st = fn () => $rowOf($schedule($a, $k), $i);
    LiteAssert::same(['unpaid', true], [$st()['status'], $st()['overdue']]);
    $inv = $docFrom($a, $i);
    LiteAssert::same('unpaid', $st()['status'], 'draft invoice');
    $set = fn (string $status, string $paid) => $a->pdo->exec("UPDATE invoices SET status = '{$status}', paid_total = {$paid} WHERE id = {$inv}");
    $set('issued', '0');
    LiteAssert::same(['issued', true], [$st()['status'], $st()['overdue']]);
    $set('issued', '400');
    LiteAssert::same('partially_paid', $st()['status']);
    $set('issued', '1000');
    LiteAssert::same(['paid', false], [$st()['status'], $st()['overdue']]);
    $set('issued', '0');
    $credit($a, $inv, '1000');
    LiteAssert::same('paid', $st()['status'], 'fully credited: nothing left to collect');
    $a->pdo->exec("DELETE FROM credit_notes WHERE invoice_id = {$inv}");
    $set('issued', '700');
    $credit($a, $inv, '300');
    LiteAssert::same('paid', $st()['status'], '700 paid + 300 credited');

    $set('cancelled', '0');
    $a->pdo->exec("DELETE FROM credit_notes WHERE invoice_id = {$inv}");
    LiteAssert::same('unpaid', $st()['status'], 'a cancelled invoice is no longer active');
    LiteAssert::true($st()['can_create_invoice'], 'can be billed again');

    $draft = $docFrom($a, $i);
    $c($a)->make(InvoiceService::class)->delete($a->admin, $draft);
    LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM finance_document_links WHERE document_id = {$draft} AND document_type = 'invoice'")->fetchColumn(), 'links go with the draft');
    LiteAssert::true($st()['can_create_invoice']);
};

$tests['14. an installment never changes money: Billing summary and document totals identical with or without it'] = function () use ($boot, $h, $contract, $add, $billing, $docInput, $canFor, $docFrom) {
    $a = $boot();
    $k = $contract($a);
    $plain = $billing($a)->createLinked($a->admin, 'invoice', $docInput($h->cur[0], '1234.56'), 'contract', $k);
    $a->pdo->exec("UPDATE invoices SET status = 'issued', paid_total = 200 WHERE id = " . (int) $plain['id']);
    $summaryBefore = $billing($a)->panelFor($a->admin, $k, $canFor($a, $a->admin))['summary'];
    $add($a, $k, '99999');
    $add($a, $k, '5');
    LiteAssert::same($summaryBefore, $billing($a)->panelFor($a->admin, $k, $canFor($a, $a->admin))['summary'], 'installments are not billed, paid or receivable');

    $i = $add($a, $k, '1234.56');
    $fromInstallment = $billing($a)->createLinked($a->admin, 'invoice', $docInput($h->cur[0], '1234.56'), 'installment', $i);
    $fromContract = $billing($a)->createLinked($a->admin, 'invoice', $docInput($h->cur[0], '1234.56'), 'contract', $k);
    foreach (['subtotal', 'tax_total', 'grand_total', 'paid_total', 'currency_id', 'status'] as $f) {
        LiteAssert::same((string) $fromContract[$f], (string) $fromInstallment[$f], "same {$f}");
    }
};

$tests['15. conversion: proforma from an installment → invoice carries contract + installment links; locked re-check refuses a second conversion'] = function () use ($boot, $h, $contract, $add, $docFrom, $c, $schedule, $rowOf, $billing, $docInput) {
    $a = $boot();
    $k = $contract($a);
    $i = $add($a, $k);
    $pf = $docFrom($a, $i, 'proforma');
    $inv = $c($a)->make(InvoiceService::class)->createFromProforma($a->admin, $pf);
    $links = $a->pdo->query("SELECT linked_type, linked_id FROM finance_document_links WHERE document_type = 'invoice' AND document_id = " . (int) $inv['id'] . ' ORDER BY linked_type')->fetchAll(\PDO::FETCH_KEY_PAIR);
    LiteAssert::same(['contract' => (string) $k, 'installment' => (string) $i], array_map('strval', $links));
    LiteAssert::same('converted', $a->pdo->query("SELECT status FROM proformas WHERE id = {$pf}")->fetchColumn());
    $row = $rowOf($schedule($a, $k), $i);
    LiteAssert::same(['invoice', (int) $inv['id']], [$row['document']['type'], $row['document']['id']], 'the invoice is now the active document');
    LiteAssert::throws(ValidationException::class, fn () => $c($a)->make(InvoiceService::class)->createFromProforma($a->admin, $pf));

    // A plain contract proforma still hands over its contract link only (existing behaviour).
    $plain = $billing($a)->createLinked($a->admin, 'proforma', $docInput($h->cur[0]), 'contract', $k);
    $inv2 = $c($a)->make(InvoiceService::class)->createFromProforma($a->admin, (int) $plain['id']);
    LiteAssert::same(['contract'], $a->pdo->query("SELECT linked_type FROM finance_document_links WHERE document_type = 'invoice' AND document_id = " . (int) $inv2['id'])->fetchAll(\PDO::FETCH_COLUMN));
    // A proforma with no link at all converts exactly as before.
    $bare = $c($a)->make(ProformaService::class)->create($a->admin, $docInput($h->cur[0]));
    $inv3 = $c($a)->make(InvoiceService::class)->createFromProforma($a->admin, (int) $bare['id']);
    LiteAssert::same('draft', $inv3['status']);
    LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM finance_document_links WHERE document_type = 'invoice' AND document_id = " . (int) $inv3['id'])->fetchColumn());
};

$tests['16. visibility: a document the user may not open is "restricted" — no number, amount, currency, status, overdue or review flag'] = function () use ($boot, $contract, $add, $docFrom, $schedule, $rowOf, $userWith, $en) {
    $a = $boot();
    $k = $contract($a);
    $i = $add($a, $k, '1000', ['due_date' => date('Y-m-d', strtotime('-3 days'))]);
    $inv = $docFrom($a, $i, 'invoice', null, 'issued', '0', '1500'); // admin-owned, amount differs
    $number = 'INV-LT-' . strtoupper(bin2hex(random_bytes(3)));
    $a->pdo->exec("UPDATE invoices SET invoice_number = '{$number}' WHERE id = {$inv}");
    $ownViewer = $userWith($a, ['contracts.view' => 'organization', 'invoices.view' => 'own']);

    $r = $rowOf($schedule($a, $k, $ownViewer), $i);
    LiteAssert::same([true, null, null, null, false], [$r['restricted'], $r['document'], $r['status'], $r['review'], $r['overdue']]);
    $adminRow = $rowOf($schedule($a, $k), $i);
    LiteAssert::same([false, $number, 'issued', 'amount', true], [$adminRow['restricted'], $adminRow['document']['number'], $adminRow['status'], $adminRow['review'], $adminRow['overdue']]);

    $a->as($ownViewer);
    $page = $a->request('GET', "/contracts/{$k}")['body'];
    $a->as($a->admin);
    $start = strpos($page, '<h3>' . htmlspecialchars($en['finance.installment.title'], ENT_QUOTES) . '</h3>');
    LiteAssert::true($start !== false, 'schedule shown');
    $section = substr($page, $start);
    LiteAssert::contains($en['finance.installment.document_restricted'], $section);
    foreach ([$number, '1,500', '/finance/invoices/' . $inv, $en['finance.installment.overdue'], $en['finance.installment.status.issued'], $en['finance.installment.review_amount']] as $secret) {
        LiteAssert::notContains($secret, $section, "not disclosed: {$secret}");
    }
};

$tests['17. review warnings: other currency → no amount comparison; same currency, other amount → both amounts'] = function () use ($boot, $h, $contract, $add, $docFrom, $schedule, $rowOf) {
    $a = $boot();
    $k = $contract($a);
    $i = $add($a, $k, '1000');
    $inv = $docFrom($a, $i, 'invoice', null, 'draft', '0', '1000');
    LiteAssert::same(null, $rowOf($schedule($a, $k), $i)['review'], 'same currency and amount');
    $a->pdo->exec("UPDATE invoices SET grand_total = 900 WHERE id = {$inv}");
    $r = $rowOf($schedule($a, $k), $i);
    LiteAssert::same(['amount', 0], [$r['review'], bccomp('900', $r['document']['amount'], 8)]);
    $a->pdo->exec("UPDATE invoices SET currency_id = {$h->cur[1]} WHERE id = {$inv}");
    LiteAssert::same('currency', $rowOf($schedule($a, $k), $i)['review'], 'currency first; amounts not compared');
};

$tests['18. Patch B kept: no credit-note amount or label in the schedule for a user without credit_notes.view'] = function () use ($boot, $contract, $add, $docFrom, $credit, $userWith, $en) {
    $a = $boot();
    $k = $contract($a);
    $i = $add($a, $k, '1000');
    $inv = $docFrom($a, $i, 'invoice', null, 'issued', '0', '1000');
    $credit($a, $inv, '321');
    $viewer = $userWith($a, ['contracts.view' => 'organization', 'invoices.view' => 'organization']);
    $a->as($viewer);
    $page = $a->request('GET', "/contracts/{$k}")['body'];
    $a->as($a->admin);
    $section = substr($page, (int) strpos($page, '<h3>' . htmlspecialchars($en['finance.installment.title'], ENT_QUOTES) . '</h3>'));
    LiteAssert::notContains('321', $section);
    LiteAssert::notContains($en['finance.credited'], $section);
    LiteAssert::notContains($en['credit_notes.total_credited'], $section);
};

$tests['19. panel: schedule + add form for a manager; no schedule without invoices.view; no add form without contracts.edit or once terminated'] = function () use ($boot, $contract, $add, $userWith, $en) {
    $a = $boot();
    $k = $contract($a);
    $add($a, $k);
    $title = '<h3>' . htmlspecialchars($en['finance.installment.title'], ENT_QUOTES) . '</h3>';
    $form = 'action="/finance/contracts/' . $k . '/installments"';
    $page = fn (array $u) => (function () use ($a, $u, $k) { $a->as($u); try { return $a->request('GET', "/contracts/{$k}")['body']; } finally { $a->as($a->admin); } })();

    $admin = $page($a->admin);
    LiteAssert::contains($title, $admin);
    LiteAssert::contains($form, $admin);
    LiteAssert::contains('/finance/installments/', $admin, 'manage link');

    $quotesOnly = $userWith($a, ['contracts.view' => 'organization', 'quotes.view' => 'organization', 'contracts.edit' => 'organization']);
    LiteAssert::notContains($title, $page($quotesOnly), 'no invoices.view → no schedule');

    $viewer = $userWith($a, ['contracts.view' => 'organization', 'invoices.view' => 'organization']);
    $v = $page($viewer);
    LiteAssert::contains($title, $v);
    LiteAssert::notContains($form, $v, 'no contracts.edit → no add form');
    LiteAssert::notContains('/finance/installments/', $v);

    $a->pdo->exec("UPDATE contracts SET status = 'terminated' WHERE id = {$k}");
    $t = $page($a->admin);
    LiteAssert::notContains($form, $t, 'terminated → no add form');
    LiteAssert::notContains('from_installment=', $t, 'terminated → no new documents');
    LiteAssert::contains('/finance/installments/', $t, 'void still offered');
};

$tests['20. all five languages: schedule and edit page render, no raw keys'] = function () use ($boot, $contract, $add) {
    $a = $boot();
    $k = $contract($a);
    $i = $add($a, $k);
    try {
        foreach (['en', 'fa', 'ar', 'tr', 'fr'] as $locale) {
            $a->locale($locale);
            $lang = require dirname(__DIR__, 2) . "/lang/{$locale}.php";
            foreach (["/contracts/{$k}", "/finance/installments/{$i}/edit"] as $path) {
                $body = $a->request('GET', $path)['body'];
                LiteAssert::notContains('finance.installment.', $body, "{$locale} {$path}: raw key");
            }
            LiteAssert::contains(htmlspecialchars($lang['finance.installment.title'], ENT_QUOTES), $a->request('GET', "/contracts/{$k}")['body'], $locale);
        }
    } finally {
        $a->locale('en');
    }
};

$tests['21. contract status: Finance\'s reading is identical to ContractService::effectiveStatus()'] = function () use ($boot, $c) {
    $a = $boot();
    $contracts = $c($a)->make(\Modules\Contracts\Services\ContractService::class);
    $today = '2026-06-15';
    foreach (['draft', 'pending_approval', 'active', 'expired', 'terminated', 'cancelled'] as $status) {
        foreach ([null, '2026-06-14', '2026-06-15', '2026-06-16'] as $end) {
            $row = ['status' => $status, 'end_date' => $end];
            LiteAssert::same($contracts->effectiveStatus($row, $today), InstallmentService::contractStatus($row, $today), "{$status} / " . ($end ?? 'no end'));
        }
    }
};

$tests['22. closed period: the schedule is not a posting (create / edit / void allowed); issuing the invoice is still period-locked'] = function () use ($boot, $contract, $add, $svc, $docFrom, $c) {
    $a = $boot();
    $a->pdo->prepare("INSERT INTO financial_periods (organization_id, starts_on, ends_on, status) VALUES (?, '2001-01-01', '2001-12-31', 'closed')")->execute([$a->orgId]);
    $k = $contract($a);
    $i = $add($a, $k, '10', ['due_date' => '2001-06-01']);
    $svc($a)->update($a->admin, $i, ['due_date' => '2001-07-01', 'amount' => '11']);
    $j = $add($a, $k, '10', ['due_date' => '2001-06-01']);
    $svc($a)->void($a->admin, $j, 'closed period is fine');
    $inv = $docFrom($a, $i);
    $a->pdo->exec("UPDATE invoices SET issue_date = '2001-07-01' WHERE id = {$inv}");
    LiteAssert::throws(ValidationException::class, fn () => $c($a)->make(InvoiceService::class)->issue($a->admin, $inv));
};

$tests['23. edit page: 403 without contracts.edit, renders for a manager, shows why an installment can\'t be edited'] = function () use ($boot, $contract, $add, $docFrom, $userWith, $en) {
    $a = $boot();
    $k = $contract($a);
    $i = $add($a, $k);
    $viewer = $userWith($a, ['contracts.view' => 'organization', 'invoices.view' => 'organization']);
    $a->as($viewer);
    LiteAssert::same('forbidden', $a->outcome('GET', "/finance/installments/{$i}/edit"));
    $a->as($a->admin);
    $page = $a->request('GET', "/finance/installments/{$i}/edit")['body'];
    LiteAssert::contains('action="/finance/installments/' . $i . '"', $page);
    LiteAssert::contains('action="/finance/installments/' . $i . '/void"', $page);
    $docFrom($a, $i);
    $locked = $a->request('GET', "/finance/installments/{$i}/edit")['body'];
    LiteAssert::notContains('action="/finance/installments/' . $i . '"', $locked);
    LiteAssert::notContains('/void"', $locked);
    LiteAssert::contains(htmlspecialchars($en['finance.installment.error.has_active_document'], ENT_QUOTES), $locked);
};

$tests['rollback leaves no test data behind'] = function () use ($boot) {
    $a = $boot();
    $a->rollBack();
    LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM contracts WHERE title = 'LiteTest S2 contract'")->fetchColumn());
    LiteAssert::same(0, (int) $a->pdo->query('SELECT COUNT(*) FROM finance_installments')->fetchColumn());
    LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM roles WHERE `key` LIKE 'litetest_s2_%'")->fetchColumn());
};

return $tests;
