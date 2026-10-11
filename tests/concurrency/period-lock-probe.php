<?php
// Stage 5.2 (L5): race-safe financial periods, with REAL concurrent transactions (two processes).
// crm_local only (LocalApp refuses anything else). Every scenario commits its own temporary rows
// (periods in 1981–1993, invoices noted PERIOD-PROBE) and everything is deleted in finally.
//
// THIS process opens a transaction and does the first step — the real FinancialPeriodService
// (which joins the open transaction) for close / reopen / create, or the payment protocol's own
// locks (invoice row FOR UPDATE + FinancialPeriodRepository::isDateLockedForUpdate) for a write
// in flight — then a child process (period-lock-child.php) sends the competing operation through
// the real services; this process commits (or rolls back) after 2 s. The final PERSISTED state is
// read back for every scenario.
// Usage: php tests/concurrency/period-lock-probe.php [scenario ...]
$root = dirname(__DIR__, 2);
require $root . '/app/autoload.php';
require $root . '/tests/lite/Assert.php';
require $root . '/tests/lite/Support/LocalApp.php';

$a = Tests\Lite\Support\LocalApp::boot();
$a->rollBack(); // autocommit: each scenario's setup is committed
$a->locale('en');
$pdo = $a->pdo;
$org = $a->orgId;
$admin = (int) $a->admin['id'];
$auth = $a->container()->make(App\Infrastructure\Authorization\AuthorizationEngine::class);
$periodRepo = new Modules\Finance\FinancialPeriodRepository();
$periods = new Modules\Finance\Services\FinancialPeriodService($periodRepo, $auth);
$irr = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'IRR'")->fetchColumn();
$org2 = (int) $pdo->query("SELECT u.organization_id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.`key` = 'org_admin' AND u.organization_id <> {$org} AND u.status = 'active' ORDER BY u.organization_id LIMIT 1")->fetchColumn();
$user2 = (int) $pdo->query("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.`key` = 'org_admin' AND u.organization_id = {$org2} AND u.status = 'active' ORDER BY u.id LIMIT 1")->fetchColumn();

$made = ['periods' => [], 'invoices' => []];
$period = function (string $year, string $status = 'open', ?int $o = null) use ($pdo, $org, &$made): int {
    $o ??= $org;
    if ((int) $pdo->query("SELECT COUNT(*) FROM financial_periods WHERE organization_id = {$o} AND starts_on <= '{$year}-12-31' AND ends_on >= '{$year}-01-01'")->fetchColumn() > 0) {
        exit("a period already covers {$year} in organization {$o}; probe not run\n");
    }
    $pdo->prepare("INSERT INTO financial_periods (organization_id, starts_on, ends_on, status) VALUES (?, ?, ?, ?)")->execute([$o, "{$year}-01-01", "{$year}-12-31", $status]);

    return $made['periods'][] = (int) $pdo->lastInsertId();
};
$invoice = function (string $date, string $status = 'issued', ?int $o = null, ?int $owner = null) use ($pdo, $org, $admin, $irr, &$made): int {
    $pdo->prepare("INSERT INTO invoices (organization_id, owner_id, issue_date, currency_id, subtotal, tax_total, grand_total, paid_total, status, notes) VALUES (?, ?, ?, ?, 1000, 0, 1000, 0, ?, 'PERIOD-PROBE')")
        ->execute([$o ?? $org, $owner ?? $admin, $date, $irr, $status]);

    return $made['invoices'][] = (int) $pdo->lastInsertId();
};
/** The payment protocol's own first steps, held open: document lock, then the period shared lock, then the write. */
$paymentInFlight = function (int $invoiceId, string $date) use ($pdo, $periodRepo, $org, $admin): void {
    $pdo->query("SELECT id FROM invoices WHERE id = {$invoiceId} FOR UPDATE")->fetchAll();
    if ($periodRepo->isDateLockedForUpdate($date)) {
        throw new RuntimeException('period unexpectedly closed');
    }
    $pdo->prepare("INSERT INTO payments (organization_id, invoice_id, amount, payment_method, payment_date, recorded_by_user_id) VALUES (?, ?, 10, 'cash', ?, ?)")->execute([$org, $invoiceId, $date, $admin]);
    $pdo->exec("UPDATE invoices SET paid_total = paid_total + 10 WHERE id = {$invoiceId}");
};
$run = function (callable $first, array $childArgs, bool $commit = true) use ($pdo): string {
    $pdo->beginTransaction();
    $first();
    $proc = proc_open([PHP_BINARY, __DIR__ . '/period-lock-child.php', ...$childArgs], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    usleep(2000000);
    $commit ? $pdo->commit() : $pdo->rollBack();
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    proc_close($proc);

    return trim($out);
};
$n = fn (string $sql): string => (string) $pdo->query($sql)->fetchColumn();
$status = fn (int $p): string => $n("SELECT status FROM financial_periods WHERE id = {$p}");

$all = ['write-then-close', 'close-then-payment', 'close-then-credit', 'close-then-issue', 'close-then-cancel', 'close-close',
    'reopen-race', 'reopen-then-payment', 'close-rolled-back', 'overlap-create', 'two-organizations', 'lock-timeout', 'write-blocks-new-period', 'issue-issue'];
$scenarios = array_slice($argv, 1) ?: $all;
try {
    foreach ($scenarios as $s) {
        switch ($s) {
            case 'write-then-close': // a payment is being written; close() must wait and close AFTER it
                $p = $period('1981');
                $i = $invoice('1981-06-01');
                $out = $run(fn () => $paymentInFlight($i, '1981-06-02'), ['close', (string) $p]);
                $state = "payments={$n("SELECT COUNT(*) FROM payments WHERE invoice_id = {$i}")} period={$status($p)}";
                break;
            case 'close-then-payment': // close in flight; the payment must wait, then be refused
                $p = $period('1982');
                $i = $invoice('1982-06-01');
                $out = $run(fn () => $periods->close($a->admin, $p), ['payment', (string) $i, '1982-06-02']);
                $state = "payments={$n("SELECT COUNT(*) FROM payments WHERE invoice_id = {$i}")} paid_total={$n("SELECT paid_total + 0 FROM invoices WHERE id = {$i}")} period={$status($p)}";
                break;
            case 'close-then-credit':
                $p = $period('1983');
                $i = $invoice('1983-06-01');
                $out = $run(fn () => $periods->close($a->admin, $p), ['credit', (string) $i, '1983-06-02']);
                $state = "credit_notes={$n("SELECT COUNT(*) FROM credit_notes WHERE invoice_id = {$i}")} period={$status($p)}";
                break;
            case 'close-then-issue':
                $p = $period('1984');
                $i = $invoice('1984-06-01', 'draft');
                $out = $run(fn () => $periods->close($a->admin, $p), ['issue', (string) $i]);
                $state = "invoice={$n("SELECT CONCAT(status, '/', COALESCE(invoice_number, 'no-number')) FROM invoices WHERE id = {$i}")} period={$status($p)}";
                break;
            case 'close-then-cancel':
                $p = $period('1985');
                $i = $invoice('1985-06-01');
                $out = $run(fn () => $periods->close($a->admin, $p), ['cancel', (string) $i]);
                $state = "invoice={$n("SELECT status FROM invoices WHERE id = {$i}")} period={$status($p)}";
                break;
            case 'close-close':
                $p = $period('1986');
                $out = $run(fn () => $periods->close($a->admin, $p), ['close', (string) $p]);
                $state = "period={$status($p)}";
                break;
            case 'reopen-race': // reopen still uncommitted: the period is still closed → refused (safe), nothing written
                $p = $period('1987', 'closed');
                $i = $invoice('1987-06-01');
                $out = $run(fn () => $periods->reopen($a->admin, $p), ['payment', (string) $i, '1987-06-02']);
                $state = "payments={$n("SELECT COUNT(*) FROM payments WHERE invoice_id = {$i}")} period={$status($p)}";
                break;
            case 'reopen-then-payment': // reopen committed, then an authorized payment in that period succeeds
                $p = $period('1980', 'closed');
                $i = $invoice('1980-06-01');
                $periods->reopen($a->admin, $p);
                $out = trim((string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/period-lock-child.php') . " payment {$i} 1980-06-02 2>&1"));
                $state = "payments={$n("SELECT COUNT(*) FROM payments WHERE invoice_id = {$i}")} paid_total={$n("SELECT paid_total + 0 FROM invoices WHERE id = {$i}")} period={$status($p)}";
                break;
            case 'close-rolled-back': // the close fails (rolled back); the waiting payment then succeeds
                $p = $period('1988');
                $i = $invoice('1988-06-01');
                $out = $run(fn () => $periods->close($a->admin, $p), ['payment', (string) $i, '1988-06-02'], false);
                $state = "payments={$n("SELECT COUNT(*) FROM payments WHERE invoice_id = {$i}")} period={$status($p)}";
                break;
            case 'overlap-create': // two overlapping new periods at once: exactly one survives
                $out = $run(function () use ($periods, $a, &$made) {
                    $made['periods'][] = (int) $periods->create($a->admin, ['starts_on' => '1989-01-01', 'ends_on' => '1989-12-31'])['id'];
                }, ['create', '1989-06-01', '1990-05-31']);
                $made['periods'] = array_merge($made['periods'], array_map('intval', $pdo->query("SELECT id FROM financial_periods WHERE organization_id = {$org} AND starts_on BETWEEN '1989-01-01' AND '1990-12-31'")->fetchAll(PDO::FETCH_COLUMN)));
                $state = "periods covering 1989-06-01: {$n("SELECT COUNT(*) FROM financial_periods WHERE organization_id = {$org} AND starts_on <= '1989-06-01' AND ends_on >= '1989-06-01'")}";
                break;
            case 'two-organizations': // closing organization 1's period never blocks organization 2
                if ($org2 === 0 || $user2 === 0) {
                    $out = 'skipped: no second organization with an active org_admin';
                    $state = '';
                    break;
                }
                $p = $period('1990');
                $p2 = $period('1990', 'open', $org2);
                $i2 = $invoice('1990-06-01', 'issued', $org2, $user2);
                $out = $run(fn () => $periods->close($a->admin, $p), ['payment', (string) $i2, '1990-06-02', "--as={$user2}"]);
                $state = "org2 payments={$n("SELECT COUNT(*) FROM payments WHERE invoice_id = {$i2}")} org1 period={$status($p)} org2 period={$status($p2)}";
                break;
            case 'lock-timeout': // the waiting payment times out: clear retry answer, nothing written
                $p = $period('1991');
                $i = $invoice('1991-06-01');
                $pdo->beginTransaction();
                $periods->close($a->admin, $p);
                $proc = proc_open([PHP_BINARY, __DIR__ . '/period-lock-child.php', 'payment', (string) $i, '1991-06-02', '--lock-timeout=1'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                usleep(4000000);
                $pdo->commit();
                $out = trim(stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]));
                proc_close($proc);
                $state = "payments={$n("SELECT COUNT(*) FROM payments WHERE invoice_id = {$i}")} paid_total={$n("SELECT paid_total + 0 FROM invoices WHERE id = {$i}")} period={$status($p)}";
                break;
            case 'write-blocks-new-period': // no period covers the date yet: a write in flight blocks creating one
                $i = $invoice('1992-06-01');
                $out = $run(fn () => $paymentInFlight($i, '1992-06-02'), ['create', '1992-01-01', '1992-12-31']);
                $made['periods'] = array_merge($made['periods'], array_map('intval', $pdo->query("SELECT id FROM financial_periods WHERE organization_id = {$org} AND starts_on = '1992-01-01'")->fetchAll(PDO::FETCH_COLUMN)));
                $state = "payments={$n("SELECT COUNT(*) FROM payments WHERE invoice_id = {$i}")} periods for 1992={$n("SELECT COUNT(*) FROM financial_periods WHERE organization_id = {$org} AND starts_on = '1992-01-01'")}";
                break;
            case 'issue-issue': // an issue in flight; a second issue of the same draft waits, then is refused
                $p = $period('1993');
                $i = $invoice('1993-06-01', 'draft');
                $out = $run(function () use ($pdo, $i) {
                    $pdo->query("SELECT id FROM invoices WHERE id = {$i} FOR UPDATE")->fetchAll();
                    $pdo->exec("UPDATE invoices SET status = 'issued', invoice_number = 'PROBE-ISSUED' WHERE id = {$i}");
                }, ['issue', (string) $i]);
                $state = "invoice={$n("SELECT CONCAT(status, '/', invoice_number) FROM invoices WHERE id = {$i}")}";
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
    foreach ($made['invoices'] as $i) {
        $pdo->exec("DELETE FROM payments WHERE invoice_id = {$i}");
        $pdo->exec("DELETE FROM credit_notes WHERE invoice_id = {$i}");
        $pdo->exec("DELETE FROM timeline_events WHERE subject_type = 'invoice' AND subject_id = {$i}");
        $pdo->exec("DELETE FROM invoices WHERE id = {$i} AND notes = 'PERIOD-PROBE'");
    }
    foreach (array_unique($made['periods']) as $p) {
        $pdo->exec("DELETE FROM timeline_events WHERE subject_type = 'financial_period' AND subject_id = {$p}");
        $pdo->exec("DELETE FROM financial_periods WHERE id = {$p} AND starts_on < '2000-01-01'");
    }
}
echo 'leftover rows: ', (int) $pdo->query("SELECT (SELECT COUNT(*) FROM invoices WHERE notes = 'PERIOD-PROBE') + (SELECT COUNT(*) FROM financial_periods WHERE starts_on < '2000-01-01') + (SELECT COUNT(*) FROM credit_notes WHERE reason = 'PERIOD-PROBE')")->fetchColumn(), "\n";
