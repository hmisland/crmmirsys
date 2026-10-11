<?php
// Stage 5.2 (L1): the same submission from two processes at once must create ONE record.
// crm_local only; needs Finance migration 0023 (refuses to run otherwise). THIS process holds the
// first submission open (expense: the real ExpenseService joins the open transaction; payment /
// credit note: the service's own first steps — claim the key, lock the invoice, write, attach),
// a child sends the identical submission through the real service; commit (or roll back) after 2 s.
// Final persisted state is read back. Every row is deleted in finally.
// Usage: php tests/concurrency/submission-key-probe.php [scenario ...]
$root = dirname(__DIR__, 2);
require $root . '/app/autoload.php';
require $root . '/tests/lite/Assert.php';
require $root . '/tests/lite/Support/LocalApp.php';

use Modules\Finance\SubmissionKeyRepository as Keys;

$a = Tests\Lite\Support\LocalApp::boot();
$a->rollBack();
$a->locale('en');
$pdo = $a->pdo;
if ($pdo->query("SHOW TABLES LIKE 'finance_submission_keys'")->fetchColumn() === false) {
    exit("finance_submission_keys missing: apply Finance migration 0023 first (approval required)\n");
}
$c = $a->container();
$auth = $c->make(App\Infrastructure\Authorization\AuthorizationEngine::class);
$keys = new Keys();
$irr = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'IRR'")->fetchColumn();
$admin = (int) $a->admin['id'];
$expenses = new Modules\Finance\Services\ExpenseService(new Modules\Finance\ExpenseRepository(), $auth, $c->make(App\Application\Services\TimelineService::class),
    $c->make(App\Application\Services\AttachmentService::class), $c->make(App\Infrastructure\Records\RecordTypeRegistry::class));
$invoices = [];
$invoice = function () use ($pdo, $a, $irr, $admin, &$invoices): int {
    $pdo->prepare("INSERT INTO invoices (organization_id, owner_id, issue_date, currency_id, subtotal, tax_total, grand_total, paid_total, status, notes) VALUES (?, ?, CURDATE(), ?, 500, 0, 500, 0, 'issued', 'KEY-PROBE')")
        ->execute([$a->orgId, $admin, $irr]);

    return $invoices[] = (int) $pdo->lastInsertId();
};
$run = function (callable $first, array $childArgs, bool $commit = true) use ($pdo): array {
    $pdo->beginTransaction();
    $mine = $first();
    $proc = proc_open([PHP_BINARY, __DIR__ . '/submission-key-child.php', ...$childArgs], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    usleep(2000000);
    $commit ? $pdo->commit() : $pdo->rollBack();
    $out = trim(stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]));
    proc_close($proc);

    return [$mine, $out];
};
$n = fn (string $sql): int => (int) $pdo->query($sql)->fetchColumn();
$scenarios = array_slice($argv, 1) ?: ['expense-twice', 'payment-twice', 'credit-twice', 'first-fails-then-retry'];
try {
    foreach ($scenarios as $s) {
        $k = Keys::newKey();
        switch ($s) {
            case 'expense-twice':
                [$mine, $out] = $run(fn () => (int) $expenses->create($a->admin, ['category' => 'other', 'description' => 'KEY-PROBE', 'expense_date' => date('Y-m-d'), 'amount' => '9', 'currency_id' => (string) $irr, 'submission_key' => $k])['id'], ['expense', $k]);
                $state = "expenses with this key: {$n("SELECT COUNT(*) FROM finance_expenses WHERE description = 'KEY-PROBE'")} | first id {$mine}";
                break;
            case 'payment-twice':
                $i = $invoice();
                [$mine, $out] = $run(function () use ($keys, $k, $admin, $pdo, $i, $a) {
                    $keys->claim(Keys::SCOPE_PAYMENT, $k, $admin);
                    $pdo->query("SELECT id FROM invoices WHERE id = {$i} FOR UPDATE")->fetchAll();
                    $pdo->prepare("INSERT INTO payments (organization_id, invoice_id, amount, payment_method, payment_date, recorded_by_user_id) VALUES (?, ?, 10, 'cash', CURDATE(), ?)")->execute([$a->orgId, $i, $admin]);
                    $id = (int) $pdo->lastInsertId();
                    $keys->attach(Keys::SCOPE_PAYMENT, $k, $id);
                    $pdo->exec("UPDATE invoices SET paid_total = paid_total + 10 WHERE id = {$i}");

                    return $id;
                }, ['payment', (string) $i, $k]);
                $state = "payments={$n("SELECT COUNT(*) FROM payments WHERE invoice_id = {$i}")} paid_total={$n("SELECT paid_total FROM invoices WHERE id = {$i}")} | first id {$mine}";
                break;
            case 'credit-twice':
                $i = $invoice();
                [$mine, $out] = $run(function () use ($keys, $k, $admin, $pdo, $i, $a) {
                    $keys->claim(Keys::SCOPE_CREDIT_NOTE, $k, $admin);
                    $pdo->query("SELECT id FROM invoices WHERE id = {$i} FOR UPDATE")->fetchAll();
                    $pdo->prepare("INSERT INTO credit_notes (organization_id, invoice_id, credit_note_number, issue_date, reason, amount, issued_by_user_id) VALUES (?, ?, ?, CURDATE(), 'KEY-PROBE', 10, ?)")->execute([$a->orgId, $i, 'KP-' . bin2hex(random_bytes(3)), $admin]);
                    $id = (int) $pdo->lastInsertId();
                    $keys->attach(Keys::SCOPE_CREDIT_NOTE, $k, $id);

                    return $id;
                }, ['credit', (string) $i, $k]);
                $state = "credit_notes={$n("SELECT COUNT(*) FROM credit_notes WHERE invoice_id = {$i}")} | first id {$mine}";
                break;
            case 'first-fails-then-retry': // the first attempt is rolled back: the waiting repeat becomes the one that writes
                $i = $invoice();
                [, $out] = $run(fn () => $keys->claim(Keys::SCOPE_PAYMENT, $k, $admin), ['payment', (string) $i, $k], false);
                $state = "payments={$n("SELECT COUNT(*) FROM payments WHERE invoice_id = {$i}")}";
                break;
            default:
                exit("unknown scenario {$s}\n");
        }
        printf("%-24s child=%s\n%26s%s\n", $s, $out, '', $state);
    }
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    foreach (array_map('intval', $pdo->query("SELECT id FROM finance_expenses WHERE description = 'KEY-PROBE'")->fetchAll(PDO::FETCH_COLUMN)) as $x) {
        $pdo->exec("DELETE FROM timeline_events WHERE subject_type = 'expense' AND subject_id = {$x}");
        $pdo->exec("DELETE FROM finance_submission_keys WHERE scope = 'expense' AND resource_id = {$x}");
        $pdo->exec("DELETE FROM finance_expenses WHERE id = {$x}");
    }
    foreach ($invoices as $i) {
        $pdo->exec("DELETE FROM finance_submission_keys WHERE scope = 'payment' AND resource_id IN (SELECT id FROM payments WHERE invoice_id = {$i})");
        $pdo->exec("DELETE FROM finance_submission_keys WHERE scope = 'credit_note' AND resource_id IN (SELECT id FROM credit_notes WHERE invoice_id = {$i})");
        $pdo->exec("DELETE FROM payments WHERE invoice_id = {$i}");
        $pdo->exec("DELETE FROM credit_notes WHERE invoice_id = {$i}");
        $pdo->exec("DELETE FROM timeline_events WHERE subject_type = 'invoice' AND subject_id = {$i}");
        $pdo->exec("DELETE FROM invoices WHERE id = {$i}");
    }
    $pdo->exec("DELETE FROM finance_submission_keys WHERE organization_id = {$a->orgId} AND resource_id IS NULL");
}
echo 'leftover rows: ', $n("SELECT (SELECT COUNT(*) FROM invoices WHERE notes = 'KEY-PROBE') + (SELECT COUNT(*) FROM finance_expenses WHERE description = 'KEY-PROBE')"), "\n";
