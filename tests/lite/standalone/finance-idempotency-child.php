<?php
// Child process for FinanceIdempotencyTest (L1-5): payment / credit-note services open their own
// transactions, and a failed first attempt must really roll back — so this runs with autocommit on
// crm_local. Every row it writes is deleted in finally. Prints one JSON object.
$root = dirname(__DIR__, 3);
require $root . '/app/autoload.php';
require $root . '/tests/lite/Assert.php';
require $root . '/tests/lite/Support/LocalApp.php';

use App\Core\Exceptions\ValidationException;
use Modules\Finance\SubmissionKeyRepository as Keys;

$a = Tests\Lite\Support\LocalApp::boot();
$a->rollBack();
$a->locale('en');
$pdo = $a->pdo;
$c = $a->container();
$auth = $c->make(App\Infrastructure\Authorization\AuthorizationEngine::class);
// Wired like FinanceModule, with the timeline (Stage 5.2 L4) — so a repeat can be checked for a duplicate audit event.
$timeline = $c->make(App\Application\Services\TimelineService::class);
$periods = new Modules\Finance\Services\FinancialPeriodService(new Modules\Finance\FinancialPeriodRepository(), $auth, $timeline);
$payments = new Modules\Finance\Services\PaymentService(new Modules\Finance\PaymentRepository(), new Modules\Finance\InvoiceRepository(), new Modules\Finance\CreditNoteRepository(), $auth, $periods, timeline: $timeline);
$credits = new Modules\Finance\Services\CreditNoteService(new Modules\Finance\CreditNoteRepository(), new Modules\Finance\CreditNoteNumberSeriesRepository(), new Modules\Finance\InvoiceRepository(), $auth, $periods, timeline: $timeline);
$expenses = new Modules\Finance\Services\ExpenseService(new Modules\Finance\ExpenseRepository(), $auth, $c->make(App\Application\Services\TimelineService::class),
    $c->make(App\Application\Services\AttachmentService::class), $c->make(App\Infrastructure\Records\RecordTypeRegistry::class));
$irr = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'IRR'")->fetchColumn();
$try = function (callable $fn): string {
    try {
        $fn();

        return 'ok';
    } catch (ValidationException) {
        return 'refused';
    }
};
// Test hygiene (Stage 5.2): the credit-note number series value before this run, restored in finally.
$cnSeriesBefore = $pdo->query('SELECT next_sequence FROM credit_note_number_series WHERE organization_id = ' . (int) $a->orgId)->fetchColumn();
$out = [];
$invoices = [];
$usedKeys = []; // every key this run uses is deleted in finally, whatever happened to its record
$periodId = 0;
try {
    $mk = function () use ($pdo, $a, $irr, &$invoices): int {
        $pdo->prepare("INSERT INTO invoices (organization_id, owner_id, issue_date, currency_id, subtotal, tax_total, grand_total, paid_total, status, notes) VALUES (?, ?, CURDATE(), ?, 500, 0, 500, 0, 'issued', 'LiteTest IDEM child')")
            ->execute([$a->orgId, (int) $a->admin['id'], $irr]);

        return $invoices[] = (int) $pdo->lastInsertId();
    };
    // payment submitted twice
    $i = $mk();
    $usedKeys[] = $k = Keys::newKey();
    $p1 = $payments->record($a->admin, $i, ['amount' => '100', 'payment_method' => 'cash', 'payment_date' => date('Y-m-d'), 'submission_key' => $k]);
    $p2 = $payments->record($a->admin, $i, ['amount' => '100', 'payment_method' => 'cash', 'payment_date' => date('Y-m-d'), 'submission_key' => $k]);
    $out['payments'] = (int) $pdo->query("SELECT COUNT(*) FROM payments WHERE invoice_id = {$i}")->fetchColumn();
    $out['paid_total'] = rtrim(rtrim((string) $pdo->query("SELECT paid_total FROM invoices WHERE id = {$i}")->fetchColumn(), '0'), '.');
    $out['payment_same_id'] = $p1['payment_id'] === $p2['payment_id'];
    $out['payment_events'] = (int) $pdo->query("SELECT COUNT(*) FROM timeline_events WHERE subject_type = 'invoice' AND subject_id = {$i} AND type = 'payment_recorded'")->fetchColumn();
    // credit note submitted twice
    $usedKeys[] = $k = Keys::newKey();
    $c1 = $credits->issue($a->admin, $i, ['amount' => '50', 'reason' => 'LiteTest IDEM child', 'issue_date' => date('Y-m-d'), 'submission_key' => $k]);
    $c2 = $credits->issue($a->admin, $i, ['amount' => '50', 'reason' => 'LiteTest IDEM child', 'issue_date' => date('Y-m-d'), 'submission_key' => $k]);
    $out['credit_notes'] = (int) $pdo->query("SELECT COUNT(*) FROM credit_notes WHERE invoice_id = {$i}")->fetchColumn();
    $out['credit_same_id'] = $c1['id'] === $c2['id'];
    $out['credit_events'] = (int) $pdo->query("SELECT COUNT(*) FROM timeline_events WHERE subject_type = 'invoice' AND subject_id = {$i} AND type = 'credit_note_issued'")->fetchColumn();
    // a failed attempt does not block its retry (outstanding is 500 − 100 − 50 = 350)
    $usedKeys[] = $k = Keys::newKey();
    $out['payment_first_try'] = $try(fn () => $payments->record($a->admin, $i, ['amount' => '351', 'payment_method' => 'cash', 'payment_date' => date('Y-m-d'), 'submission_key' => $k]));
    $out['payment_retry'] = $try(fn () => $payments->record($a->admin, $i, ['amount' => '350', 'payment_method' => 'cash', 'payment_date' => date('Y-m-d'), 'submission_key' => $k]));
    // expense: closed period, then corrected
    $pdo->prepare("INSERT INTO financial_periods (organization_id, starts_on, ends_on, status) VALUES (?, '1979-01-01', '1979-12-31', 'closed')")->execute([$a->orgId]);
    $periodId = (int) $pdo->lastInsertId();
    $usedKeys[] = $k = Keys::newKey();
    $e = ['category' => 'other', 'description' => 'LiteTest IDEM child', 'amount' => '5', 'currency_id' => (string) $irr, 'submission_key' => $k];
    $out['expense_first_try'] = $try(fn () => $expenses->create($a->admin, $e + ['expense_date' => '1979-05-05']));
    $out['expense_retry'] = $try(fn () => $expenses->create($a->admin, $e + ['expense_date' => date('Y-m-d')]));
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    foreach ($usedKeys as $usedKey) {
        $pdo->prepare('DELETE FROM finance_submission_keys WHERE organization_id = ? AND submission_key = ?')->execute([$a->orgId, $usedKey]);
    }
    $expIds = array_map('intval', $pdo->query("SELECT id FROM finance_expenses WHERE description = 'LiteTest IDEM child'")->fetchAll(PDO::FETCH_COLUMN));
    foreach ($expIds as $x) {
        $pdo->exec("DELETE FROM timeline_events WHERE subject_type = 'expense' AND subject_id = {$x}");
        $pdo->exec("DELETE FROM finance_submission_keys WHERE scope = 'expense' AND resource_id = {$x}");
        $pdo->exec("DELETE FROM finance_expenses WHERE id = {$x}");
    }
    foreach ($invoices as $i) {
        foreach (['payment' => 'payments', 'credit_note' => 'credit_notes'] as $scope => $table) {
            $pdo->exec("DELETE FROM finance_submission_keys WHERE scope = '{$scope}' AND resource_id IN (SELECT id FROM {$table} WHERE invoice_id = {$i})");
        }
        $pdo->exec("DELETE FROM payments WHERE invoice_id = {$i}");
        $pdo->exec("DELETE FROM credit_notes WHERE invoice_id = {$i}");
        $pdo->exec("DELETE FROM timeline_events WHERE subject_type = 'invoice' AND subject_id = {$i}");
        $pdo->exec("DELETE FROM invoices WHERE id = {$i}");
    }
    if ($periodId > 0) {
        $pdo->exec("DELETE FROM financial_periods WHERE id = {$periodId}");
    }
    if ($cnSeriesBefore !== false) {
        $pdo->prepare("UPDATE credit_note_number_series s SET s.next_sequence = :before
            WHERE s.organization_id = :org AND s.next_sequence > :before2
              AND NOT EXISTS (SELECT 1 FROM credit_notes c WHERE c.organization_id = s.organization_id
                  AND CAST(SUBSTRING(c.credit_note_number, CHAR_LENGTH(s.prefix) + 1) AS UNSIGNED) >= :before3)")
            ->execute(['before' => (int) $cnSeriesBefore, 'before2' => (int) $cnSeriesBefore, 'before3' => (int) $cnSeriesBefore, 'org' => (int) $a->orgId]);
    }
    $out['leftover'] = (int) $pdo->query("SELECT (SELECT COUNT(*) FROM invoices WHERE notes = 'LiteTest IDEM child') + (SELECT COUNT(*) FROM finance_expenses WHERE description = 'LiteTest IDEM child') + (SELECT COUNT(*) FROM financial_periods WHERE starts_on = '1979-01-01')")->fetchColumn();
}
echo json_encode($out);
