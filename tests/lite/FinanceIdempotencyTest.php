<?php

declare(strict_types=1);

/**
 * Stage 5.2 — L1: submission (idempotency) keys for expenses, payments and
 * credit notes (Finance migration 0023, finance_submission_keys).
 *
 * In-suite (one rolled-back transaction): expense repeats, legitimate twins,
 * legacy requests, other users / organizations, form keys. Payments, credit
 * notes and failure-then-retry need their own transactions → child process
 * (tests/lite/standalone/finance-idempotency-child.php); true concurrency →
 * tests/concurrency/submission-key-probe.php.
 *
 * Skipped (with the reason) until migration 0023 has been applied to crm_local.
 */

require_once __DIR__ . '/Support/LocalApp.php';

use App\Core\Exceptions\ValidationException;
use Modules\Finance\ExpenseRepository;
use Modules\Finance\Services\ExpenseService;
use Modules\Finance\SubmissionKeyRepository;
use Tests\Lite\Support\LocalApp;

$h = new \stdClass();
$h->app = null;
$boot = function () use ($h): LocalApp {
    if ($h->app !== null) {
        return $h->app;
    }
    $a = LocalApp::boot();
    if ($a->pdo->query("SHOW TABLES LIKE 'finance_submission_keys'")->fetchColumn() === false) {
        $a->rollBack();
        throw new LiteSkip('Finance migration 0023 (finance_submission_keys) not applied to crm_local yet');
    }
    $finance = (int) $a->pdo->query("SELECT id FROM modules WHERE `key` = 'finance'")->fetchColumn();
    $a->pdo->prepare('DELETE FROM organization_modules WHERE organization_id = ? AND module_id = ?')->execute([$a->orgId, $finance]);
    $a->pdo->prepare('INSERT INTO organization_modules (organization_id, module_id, enabled) VALUES (?, ?, 1)')->execute([$a->orgId, $finance]);
    $a->as($a->admin);
    $a->locale('en');
    $h->irr = (string) $a->pdo->query("SELECT id FROM currencies WHERE code = 'IRR'")->fetchColumn();
    $h->app = $a;

    return $a;
};
$svc = fn (LocalApp $a): ExpenseService => new ExpenseService(new ExpenseRepository(), $a->container()->make(\App\Infrastructure\Authorization\AuthorizationEngine::class),
    $a->container()->make(\App\Application\Services\TimelineService::class), $a->container()->make(\App\Application\Services\AttachmentService::class),
    $a->container()->make(\App\Infrastructure\Records\RecordTypeRegistry::class));
$input = fn (LocalApp $a, array $extra = []) /* currency from $h, set by $boot */ => $extra + ['category' => 'other', 'description' => 'LiteTest IDEM', 'expense_date' => date('Y-m-d'), 'amount' => '77', 'currency_id' => (string) ($h->irr ?? '')];
$rows = fn (LocalApp $a): int => (int) $a->pdo->query("SELECT COUNT(*) FROM finance_expenses WHERE description = 'LiteTest IDEM'")->fetchColumn();
$events = fn (LocalApp $a, int $id): int => (int) $a->pdo->query("SELECT COUNT(*) FROM timeline_events WHERE subject_type = 'expense' AND subject_id = {$id} AND type = 'created'")->fetchColumn();

$tests = [];

$tests['L1-1 the same expense submission twice (service and route): one expense, one audit event, the original returned'] = function () use ($boot, $h, $svc, $input, $rows, $events) {
    $a = $boot();
    $key = SubmissionKeyRepository::newKey();
    $first = $svc($a)->create($a->admin, $input($a, ['submission_key' => $key]));
    $again = $svc($a)->create($a->admin, $input($a, ['submission_key' => $key]));
    LiteAssert::same((int) $first['id'], (int) $again['id'], 'the repeat answers with the original');
    LiteAssert::same(1, $rows($a));
    LiteAssert::same(1, $events($a, (int) $first['id']), 'no duplicate audit event');
    $routeKey = SubmissionKeyRepository::newKey();
    $r1 = $a->request('POST', '/finance/expenses', [], $input($a, ['submission_key' => $routeKey]));
    $r2 = $a->request('POST', '/finance/expenses', [], $input($a, ['submission_key' => $routeKey]));
    LiteAssert::same([302, 302], [$r1['status'], $r2['status']]);
    LiteAssert::same($r1['location'], $r2['location'], 'double click lands on the same expense');
    LiteAssert::same(2, $rows($a));
};

$tests['L1-2 legitimate identical expenses (two forms, two keys) and legacy requests without a key are still allowed'] = function () use ($boot, $h, $svc, $input, $rows) {
    $a = $boot();
    $before = $rows($a);
    $svc($a)->create($a->admin, $input($a, ['submission_key' => SubmissionKeyRepository::newKey()]));
    $svc($a)->create($a->admin, $input($a, ['submission_key' => SubmissionKeyRepository::newKey()]));
    $svc($a)->create($a->admin, $input($a));
    $svc($a)->create($a->admin, $input($a, ['submission_key' => 'not-a-key']));
    LiteAssert::same($before + 4, $rows($a));
};

$tests['L1-3 a key used by another user is never answered with their record; another organization\'s key is independent'] = function () use ($boot, $h, $svc, $input) {
    $a = $boot();
    $key = SubmissionKeyRepository::newKey();
    $svc($a)->create($a->admin, $input($a, ['submission_key' => $key]));
    $a->pdo->prepare('INSERT INTO roles (organization_id, `key`, name, is_system) VALUES (?, ?, ?, 0)')->execute([$a->orgId, 'litetest_idem_' . bin2hex(random_bytes(3)), 'LiteTest IDEM']);
    $role = (int) $a->pdo->lastInsertId();
    foreach (['finance.expenses.view', 'finance.expenses.manage'] as $k) {
        $a->pdo->prepare("INSERT INTO role_permissions (role_id, permission_key, scope) VALUES (?, ?, 'organization')")->execute([$role, $k]);
    }
    $other = array_merge($a->member, ['role_id' => $role]);
    try {
        $svc($a)->create($other, $input($a, ['submission_key' => $key]));
        throw new LiteAssertionFailed('a foreign key was accepted');
    } catch (ValidationException $e) {
        LiteAssert::true(isset($e->errors['submission_key']));
    }
    if ($a->otherOrgId !== null) {
        $a->pdo->prepare("INSERT INTO finance_submission_keys (organization_id, user_id, scope, submission_key, resource_id) VALUES (?, NULL, 'expense', ?, 999999)")->execute([$a->otherOrgId, $k2 = SubmissionKeyRepository::newKey()]);
        $mine = $svc($a)->create($a->admin, $input($a, ['submission_key' => $k2]));
        LiteAssert::true((int) $mine['id'] !== 999999, 'other organization\'s key ignored');
    }
};

$tests['L1-4 forms carry a fresh key; the expense form keeps its key when shown again after an error'] = function () use ($boot, $h, $input) {
    $a = $boot();
    $grab = fn (string $html) => preg_match_all('/name="submission_key" value="([a-f0-9]{32})"/', $html, $m) ? $m[1] : [];
    $k1 = $grab($a->request('GET', '/finance/expenses/create')['body']);
    $k2 = $grab($a->request('GET', '/finance/expenses/create')['body']);
    LiteAssert::same(1, count($k1));
    LiteAssert::true($k1 !== $k2, 'a new form, a new key');
    $key = SubmissionKeyRepository::newKey();
    $r = $a->request('POST', '/finance/expenses', [], $input($a, ['submission_key' => $key, 'amount' => '-1']));
    LiteAssert::same(422, $r['status']);
    LiteAssert::same([$key], $grab($r['body']), 'same key after the error');
    $inv = (int) $a->pdo->query("SELECT id FROM invoices WHERE organization_id = {$a->orgId} AND status = 'issued' ORDER BY id LIMIT 1")->fetchColumn();
    if ($inv > 0) {
        LiteAssert::same(2, count($grab($a->request('GET', "/finance/invoices/{$inv}")['body'])), 'payment and credit-note forms');
    }
};

$tests['L1-5 payments, credit notes and failure-then-retry (own transactions → child process, cleaned up)'] = function () use ($boot) {
    $a = $boot();
    $a->rollBack(); // release this suite's locks before the child writes (see FinanceRemediationTest P3-5)
    $json = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/standalone/finance-idempotency-child.php') . ' 2>&1');
    $r = json_decode(trim($json), true);
    LiteAssert::true(is_array($r), 'child output: ' . substr($json, 0, 300));
    LiteAssert::same([1, '100'], [$r['payments'], $r['paid_total']], 'payment submitted twice: one payment, paid once');
    LiteAssert::same(true, $r['payment_same_id']);
    LiteAssert::same([1, true], [$r['credit_notes'], $r['credit_same_id']], 'credit note submitted twice');
    LiteAssert::same([1, 1], [$r['payment_events'], $r['credit_events']], 'L4: a repeated submission writes no duplicate audit event');
    LiteAssert::same('refused', $r['payment_first_try'], 'first payment attempt over the outstanding amount fails…');
    LiteAssert::same('ok', $r['payment_retry'], '…and its retry with the SAME key succeeds');
    LiteAssert::same('refused', $r['expense_first_try'], 'expense in a closed period fails…');
    LiteAssert::same('ok', $r['expense_retry'], '…and the corrected retry with the same key succeeds');
    LiteAssert::same(0, $r['leftover']);
};

$tests['rollback leaves no test data behind'] = function () use ($boot) {
    $a = $boot();
    $a->rollBack();
    LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM finance_expenses WHERE description = 'LiteTest IDEM'")->fetchColumn());
    LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM roles WHERE `key` LIKE 'litetest_idem_%'")->fetchColumn());
};

return $tests;
