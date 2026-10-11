<?php
// Child process for FinanceRemediationTest (P3-4): the payment / credit-note SUCCESS paths open
// their own transaction, which can't nest inside the suite's — so they run here, with autocommit,
// on crm_local only. Every row this script writes is deleted in finally. Prints one JSON object.
$root = dirname(__DIR__, 3);
require $root . '/app/autoload.php';
require $root . '/tests/lite/Assert.php';
require $root . '/tests/lite/Support/LocalApp.php';

use App\Core\Exceptions\ValidationException;

$a = Tests\Lite\Support\LocalApp::boot();
$a->rollBack(); // no outer transaction, as in production
$a->locale('en');
$pdo = $a->pdo;
$auth = $a->container()->make(App\Infrastructure\Authorization\AuthorizationEngine::class);
$periods = new Modules\Finance\Services\FinancialPeriodService(new Modules\Finance\FinancialPeriodRepository(), $auth);
$payments = new Modules\Finance\Services\PaymentService(new Modules\Finance\PaymentRepository(), new Modules\Finance\InvoiceRepository(), new Modules\Finance\CreditNoteRepository(), $auth, $periods);
$credits = new Modules\Finance\Services\CreditNoteService(new Modules\Finance\CreditNoteRepository(), new Modules\Finance\CreditNoteNumberSeriesRepository(), new Modules\Finance\InvoiceRepository(), $auth, $periods);
$irr = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'IRR'")->fetchColumn();
// Test hygiene (Stage 5.2): the credit-note number series value before this run, restored in finally.
$cnSeriesBefore = $pdo->query('SELECT next_sequence FROM credit_note_number_series WHERE organization_id = ' . (int) $a->orgId)->fetchColumn();
$out = [];
$refusal = function (callable $fn): string {
    try {
        $fn();

        return 'accepted';
    } catch (ValidationException $e) {
        return 'refused:' . implode(',', array_keys($e->errors)) . ':' . json_encode($e->errors, JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        return 'UNCAUGHT ' . get_class($e);
    }
};
$id = 0;
try {
    $pdo->prepare("INSERT INTO invoices (organization_id, owner_id, issue_date, currency_id, subtotal, tax_total, grand_total, paid_total, status, notes) VALUES (?, ?, CURDATE(), ?, 5000, 0, 5000, 0, 'issued', 'LiteTest FR child')")
        ->execute([$a->orgId, (int) $a->admin['id'], $irr]);
    $id = (int) $pdo->lastInsertId();
    $out['payment_1,000'] = $refusal(fn () => $payments->record($a->admin, $id, ['amount' => '1,000', 'payment_method' => 'cash', 'payment_date' => date('Y-m-d')]));
    $out['credit_1,500.50'] = $refusal(fn () => $credits->issue($a->admin, $id, ['amount' => '1,500.50', 'reason' => 'LiteTest FR child', 'issue_date' => date('Y-m-d')]));
    $out['paid_total'] = (string) $pdo->query("SELECT paid_total FROM invoices WHERE id = {$id}")->fetchColumn();
    $out['payment_amount'] = (string) $pdo->query("SELECT amount FROM payments WHERE invoice_id = {$id}")->fetchColumn();
    $out['credit_amount'] = (string) $pdo->query("SELECT amount FROM credit_notes WHERE invoice_id = {$id}")->fetchColumn();
    // caps unchanged: outstanding is 5000 − 1500.5 − 1000 = 2499.5; creditable is 5000 − 1500.5 = 3499.5
    $out['payment_over_cap'] = $refusal(fn () => $payments->record($a->admin, $id, ['amount' => '2,500', 'payment_method' => 'cash', 'payment_date' => date('Y-m-d')]));
    $out['credit_over_cap'] = $refusal(fn () => $credits->issue($a->admin, $id, ['amount' => '3,500', 'reason' => 'LiteTest FR child', 'issue_date' => date('Y-m-d')]));
    $out['payment_exact_cap'] = $refusal(fn () => $payments->record($a->admin, $id, ['amount' => '2,499.5', 'payment_method' => 'cash', 'payment_date' => date('Y-m-d')]));
    // (closed-period refusals run before any transaction: tested in-suite, P3-4)
    $out['final_paid_total'] = (string) $pdo->query("SELECT paid_total FROM invoices WHERE id = {$id}")->fetchColumn();
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($id > 0) {
        $pdo->exec("DELETE FROM payments WHERE invoice_id = {$id}");
        $pdo->exec("DELETE FROM credit_notes WHERE invoice_id = {$id}");
        $pdo->exec("DELETE FROM timeline_events WHERE subject_type = 'invoice' AND subject_id = {$id}");
        $pdo->exec("DELETE FROM invoices WHERE id = {$id} AND notes = 'LiteTest FR child'");
    }
    if ($cnSeriesBefore !== false) {
        $pdo->prepare("UPDATE credit_note_number_series s SET s.next_sequence = :before
            WHERE s.organization_id = :org AND s.next_sequence > :before2
              AND NOT EXISTS (SELECT 1 FROM credit_notes c WHERE c.organization_id = s.organization_id
                  AND CAST(SUBSTRING(c.credit_note_number, CHAR_LENGTH(s.prefix) + 1) AS UNSIGNED) >= :before3)")
            ->execute(['before' => (int) $cnSeriesBefore, 'before2' => (int) $cnSeriesBefore, 'before3' => (int) $cnSeriesBefore, 'org' => (int) $a->orgId]);
    }
    $out['leftover'] = (int) $pdo->query("SELECT (SELECT COUNT(*) FROM invoices WHERE notes = 'LiteTest FR child') + (SELECT COUNT(*) FROM credit_notes WHERE reason = 'LiteTest FR child')")->fetchColumn();
}
echo json_encode($out, JSON_UNESCAPED_UNICODE);
