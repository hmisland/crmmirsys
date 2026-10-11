<?php
// Patch A, mutation #7 (docs/invoice-cancel-note.md): a real two-connection check of the
// re-check on the row locked FOR UPDATE. crm_local only (LocalApp refuses anything else).
// Usage: php tests/concurrency/invoice-cancel-lock-probe.php [none|payment|credit ...]
// Expected: none → CANCELLED; payment / credit → REFUSED; the cancel waited ≈2 s on the lock.
//
// Parent: commits a temporary issued invoice, holds its row lock in a second connection while
// "recording" a payment / credit note (exactly what PaymentService / CreditNoteService do under
// FOR UPDATE), starts a real cancel() in another process, commits after 2 s, and reports.
// Every temporary row is deleted in finally.
$root = dirname(__DIR__, 2);
require $root . '/app/autoload.php';
require $root . '/tests/lite/Assert.php';
require $root . '/tests/lite/Support/LocalApp.php';
$a = Tests\Lite\Support\LocalApp::boot();
$a->rollBack();
$pdo = $a->pdo;
$cur = (int) $pdo->query('SELECT id FROM currencies WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
$scenarios = array_slice($argv, 1) ?: ['none', 'payment', 'credit'];
foreach ($scenarios as $scenario) {
    $pdo->prepare("INSERT INTO invoices (organization_id, owner_id, issue_date, currency_id, subtotal, grand_total, paid_total, status, notes) VALUES (?, ?, CURDATE(), ?, 1000, 1000, 0, 'issued', 'CONCURRENCY-PROBE')")
        ->execute([$a->orgId, (int) $a->admin['id'], $cur]);
    $id = (int) $pdo->lastInsertId();
    try {
        $pdo->beginTransaction();
        $pdo->query("SELECT id FROM invoices WHERE id = {$id} FOR UPDATE")->fetchAll();
        if ($scenario === 'payment') {
            $pdo->exec("UPDATE invoices SET paid_total = 100 WHERE id = {$id}");
        } elseif ($scenario === 'credit') {
            $pdo->prepare("INSERT INTO credit_notes (organization_id, invoice_id, credit_note_number, issue_date, reason, amount, issued_by_user_id) VALUES (?, ?, ?, CURDATE(), 'CONCURRENCY-PROBE', 50, ?)")
                ->execute([$a->orgId, $id, 'PROBE-' . bin2hex(random_bytes(3)), (int) $a->admin['id']]);
        }
        $child = proc_open([PHP_BINARY, __DIR__ . '/invoice-cancel-child.php', (string) $id], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        usleep(2000000);
        $pdo->commit();
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        proc_close($child);
        $final = $pdo->query("SELECT status, paid_total, (SELECT COUNT(*) FROM credit_notes WHERE invoice_id = {$id}) AS credits FROM invoices WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);
        printf("%-8s child=%s | final: status=%s paid=%s credits=%s\n", $scenario, trim($out), $final['status'], rtrim(rtrim($final['paid_total'], '0'), '.'), $final['credits']);
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $pdo->exec("DELETE FROM timeline_events WHERE subject_type = 'invoice' AND subject_id = {$id}");
        $pdo->exec("DELETE FROM credit_notes WHERE invoice_id = {$id}");
        $pdo->exec("DELETE FROM invoices WHERE id = {$id} AND notes = 'CONCURRENCY-PROBE'");
    }
}
echo 'leftover rows: ', (int) $pdo->query("SELECT COUNT(*) FROM invoices WHERE notes = 'CONCURRENCY-PROBE'")->fetchColumn()
    + (int) $pdo->query("SELECT COUNT(*) FROM credit_notes WHERE reason = 'CONCURRENCY-PROBE'")->fetchColumn(), "\n";
