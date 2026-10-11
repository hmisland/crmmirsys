<?php
// Child process for FinanceFinalizationTest: issuing, payments, credit notes and period close /
// reopen open their own transactions, so this runs with autocommit on crm_local, through the real
// routes (FinanceModule wiring) and, for the atomicity checks, through services wired the same way.
// Every row it writes is deleted in finally; the invoice / credit-note number series and the
// organization's final-rounding setting are restored (guarded). Prints one JSON object.
$root = dirname(__DIR__, 3);
require $root . '/app/autoload.php';
require $root . '/tests/lite/Assert.php';
require $root . '/tests/lite/Support/LocalApp.php';

use App\Core\Exceptions\ValidationException;
use Modules\Finance\SubmissionKeyRepository as Keys;

$a = Tests\Lite\Support\LocalApp::boot();
$a->rollBack();
$a->locale('en');
$a->as($a->admin);
$pdo = $a->pdo;
$c = $a->container();
$org = (int) $a->orgId;
$auth = $c->make(App\Infrastructure\Authorization\AuthorizationEngine::class);
$timeline = $c->make(App\Application\Services\TimelineService::class);
// The same wiring as FinanceModule (timeline injected).
$periods = new Modules\Finance\Services\FinancialPeriodService(new Modules\Finance\FinancialPeriodRepository(), $auth, $timeline);
$payments = new Modules\Finance\Services\PaymentService(new Modules\Finance\PaymentRepository(), new Modules\Finance\InvoiceRepository(), new Modules\Finance\CreditNoteRepository(), $auth, $periods, timeline: $timeline);
$credits = new Modules\Finance\Services\CreditNoteService(new Modules\Finance\CreditNoteRepository(), new Modules\Finance\CreditNoteNumberSeriesRepository(), new Modules\Finance\InvoiceRepository(), $auth, $periods, timeline: $timeline);
$report = new Modules\Finance\Services\ReportService(new Modules\Finance\InvoiceRepository(), new Modules\Finance\CreditNoteRepository(), $auth);
$irr = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'IRR'")->fetchColumn();
$usd = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'USD'")->fetchColumn();
$plain = fn ($v): ?string => $v === null ? null : (str_contains((string) $v, '.') ? rtrim(rtrim((string) $v, '0'), '.') : (string) $v);
$text = fn (string $html): string => preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html)));
$col = fn (string $sql) => $pdo->query($sql)->fetchColumn();
$events = fn (string $subject, int $id, string $type): int => (int) $col("SELECT COUNT(*) FROM timeline_events WHERE subject_type = '{$subject}' AND subject_id = {$id} AND type = '{$type}'");
$try = function (callable $fn): string {
    try {
        $fn();

        return 'ok';
    } catch (ValidationException $e) {
        return 'refused:' . implode(',', array_keys($e->errors));
    }
};

// Restored in finally.
$invSeriesBefore = $col("SELECT next_sequence FROM invoice_number_series WHERE organization_id = {$org}");
$cnSeriesBefore = $col("SELECT next_sequence FROM credit_note_number_series WHERE organization_id = {$org}");
$roundingBefore = $col("SELECT invoice_final_rounding FROM financial_profiles WHERE organization_id = {$org}");
// Invoices that exist before this run: never touched by it (no backfill, no recalculation).
$pdo->exec('SET SESSION group_concat_max_len = 16777216');
$legacyHash = fn () => $col('SELECT SHA2(GROUP_CONCAT(CONCAT_WS("|", id, grand_total, subtotal, tax_total, paid_total, status, IFNULL(final_rounding, "N"), IFNULL(unrounded_total, "N"), rounding_adjustment) ORDER BY id), 256) FROM invoices WHERE notes IS NULL OR notes <> "LiteTest FIN child"');
$legacyBefore = $legacyHash();

$out = ['skip' => null];
$invoices = [];
$usedKeys = [];
$periodIds = [];
$locker = null;
try {
    if ($irr === 0 || $usd === 0 || $roundingBefore === false) {
        $out['skip'] = 'needs IRR, USD and a financial profile';
        throw new RuntimeException('skip');
    }
    if ((int) $col("SELECT COUNT(*) FROM financial_periods WHERE organization_id = {$org} AND status = 'closed' AND starts_on <= '1983-12-31' AND ends_on >= '1981-01-01'") > 0) {
        $out['skip'] = 'a closed period covers the test dates';
        throw new RuntimeException('skip');
    }
    $mk = function (int $currency, string $grand, string $status = 'draft', string $date = '1981-03-10') use ($pdo, $a, $org, &$invoices): int {
        $pdo->prepare("INSERT INTO invoices (organization_id, owner_id, issue_date, currency_id, subtotal, tax_total, grand_total, paid_total, status, notes) VALUES (?, ?, ?, ?, ?, 0, ?, 0, ?, 'LiteTest FIN child')")
            ->execute([$org, (int) $a->admin['id'], $date, $currency, $grand, $grand, $status]);

        return $invoices[] = (int) $pdo->lastInsertId();
    };
    $setDefault = fn (int $on) => $pdo->exec("UPDATE financial_profiles SET invoice_final_rounding = {$on} WHERE organization_id = {$org}");
    $issue = function (int $id, ?string $choice) use ($a): int {
        return $a->request('POST', "/finance/invoices/{$id}/issue", [], $choice === null ? [] : ['final_rounding' => $choice])['status'];
    };
    $snap = function (int $id) use ($pdo, $plain): array {
        $r = $pdo->query("SELECT status, grand_total, unrounded_total, rounding_adjustment, final_rounding, rounding_precision FROM invoices WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);

        return [$r['status'], $plain($r['grand_total']), $plain($r['unrounded_total']), $plain($r['rounding_adjustment']), $r['final_rounding'] === null ? null : (int) $r['final_rounding'], $r['rounding_precision'] === null ? null : (int) $r['rounding_precision']];
    };

    // ---- R: final rounding at issuance (route → controller → InvoiceService::issue)
    $setDefault(0);
    $r1 = $mk($irr, '1000.4');
    $out['r1_status'] = $issue($r1, '1');
    $out['r1'] = $snap($r1);                                   // rounded on request
    $r2 = $mk($irr, '1000.4');
    $issue($r2, '0');
    $out['r2'] = $snap($r2);                                   // explicitly not rounded
    $r3 = $mk($irr, '1000.5');
    $issue($r3, '1');
    $out['r3'] = $snap($r3);                                   // half-up → 1001
    $setDefault(1);
    $r4 = $mk($irr, '2000.25');
    $issue($r4, null);
    $out['r4'] = $snap($r4);                                   // organization default ON
    $r6 = $mk($irr, '2000.25');
    $issue($r6, '0');
    $out['r6'] = $snap($r6);                                   // per-invoice override of the ON default
    $setDefault(0);
    $r5 = $mk($irr, '2000.25');
    $issue($r5, null);
    $out['r5'] = $snap($r5);                                   // organization default OFF
    $u1 = $mk($usd, '10.555', 'draft', '1981-04-10');
    $issue($u1, '1');
    $out['u1'] = $snap($u1);                                   // USD → its precision (2)
    $d1 = $mk($irr, '7.7');
    $out['draft_untouched'] = $snap($d1);                      // drafts carry no snapshot
    $out['issued_page'] = $text($a->request('GET', "/finance/invoices/{$r1}")['body']);
    $out['plain_page'] = $text($a->request('GET', "/finance/invoices/{$r2}")['body']);
    $out['print_page'] = $text($a->request('GET', "/finance/invoices/{$r1}/print")['body']);

    // ---- E: the adjustment counted exactly once (payments, credits, outstanding, report)
    $out['pay_unrounded'] = $a->request('POST', "/finance/invoices/{$r1}/payments", [], ['amount' => '1000.4', 'payment_method' => 'cash', 'payment_date' => '1981-03-11'])['status'];
    $out['payments_after_refusal'] = (int) $col("SELECT COUNT(*) FROM payments WHERE invoice_id = {$r1}");
    $usedKeys[] = $k = Keys::newKey();
    foreach ([1, 2] as $_) { // the same submission twice: one payment, one audit event (L1 + L4)
        $a->request('POST', "/finance/invoices/{$r1}/payments", [], ['amount' => '600', 'payment_method' => 'bank_transfer', 'payment_date' => '1981-03-11', 'submission_key' => $k]);
    }
    $out['payments'] = (int) $col("SELECT COUNT(*) FROM payments WHERE invoice_id = {$r1}");
    $out['payment_events'] = $events('invoice', $r1, 'payment_recorded');
    $out['payment_event_text'] = (string) $col("SELECT description FROM timeline_events WHERE subject_type = 'invoice' AND subject_id = {$r1} AND type = 'payment_recorded'");
    $out['payment_event_actor'] = (int) $col("SELECT actor_user_id FROM timeline_events WHERE subject_type = 'invoice' AND subject_id = {$r1} AND type = 'payment_recorded'") === (int) $a->admin['id'];
    $out['credit_over'] = $a->request('POST', "/finance/invoices/{$r1}/credit-notes", [], ['amount' => '1000.4', 'reason' => 'LiteTest FIN child', 'issue_date' => '1981-03-12'])['status'];
    $out['credit_notes_after_refusal'] = (int) $col("SELECT COUNT(*) FROM credit_notes WHERE invoice_id = {$r1}");
    $usedKeys[] = $k = Keys::newKey();
    foreach ([1, 2] as $_) {
        $a->request('POST', "/finance/invoices/{$r1}/credit-notes", [], ['amount' => '400', 'reason' => 'LiteTest FIN child', 'issue_date' => '1981-03-12', 'submission_key' => $k]);
    }
    $out['credit_notes'] = (int) $col("SELECT COUNT(*) FROM credit_notes WHERE invoice_id = {$r1}");
    $out['credit_events'] = $events('invoice', $r1, 'credit_note_issued');
    $out['credit_event_text'] = (string) $col("SELECT description FROM timeline_events WHERE subject_type = 'invoice' AND subject_id = {$r1} AND type = 'credit_note_issued'");
    $row = $pdo->query("SELECT * FROM invoices WHERE id = {$r1}")->fetch(PDO::FETCH_ASSOC);
    $credited = (new Modules\Finance\CreditNoteRepository())->totalCreditedForInvoice($r1);
    $out['r1_status_after'] = Modules\Finance\Services\PaymentService::paymentStatus($row, $credited);
    $out['r1_outstanding'] = $plain(bcsub(bcsub((string) $row['grand_total'], $credited, 18), (string) $row['paid_total'], 18));
    $out['history_page'] = $text($a->request('GET', "/finance/invoices/{$r1}")['body']);
    $rep = $report->summary($a->admin, '1981-03-01', '1981-03-31')['currencies'][$irr] ?? null;
    $out['report'] = $rep === null ? null : array_map($plain, array_intersect_key($rep, array_flip(['gross_revenue', 'total_credited', 'net_revenue', 'total_paid', 'outstanding'])));
    $out['report_usd'] = isset($report->summary($a->admin, '1981-03-01', '1981-03-31')['currencies'][$usd]);

    // ---- T: the audit event is in the SAME transaction — when its insert fails, the record is rolled back too
    $t1 = $mk($irr, '500', 'issued', '1982-02-02');
    $pdo->prepare("INSERT INTO financial_periods (organization_id, starts_on, ends_on, status) VALUES (?, '1983-01-01', '1983-12-31', 'open')")->execute([$org]);
    $periodIds[] = $p1 = (int) $pdo->lastInsertId();
    $cfg = App\Infrastructure\Config\Config::get('database.connections.' . App\Infrastructure\Config\Config::get('database.default'));
    $locker = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $cfg['host'] ?? '127.0.0.1', $cfg['port'] ?? '3306', $cfg['database']), $cfg['username'] ?? '', $cfg['password'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $cnMid = $col("SELECT next_sequence FROM credit_note_number_series WHERE organization_id = {$org}");
    $locker->exec('LOCK TABLES timeline_events WRITE');
    $pdo->exec('SET SESSION lock_wait_timeout = 1');
    $out['atomic_payment'] = $try(fn () => $payments->record($a->admin, $t1, ['amount' => '100', 'payment_method' => 'cash', 'payment_date' => '1982-02-03']));
    $out['atomic_credit'] = $try(fn () => $credits->issue($a->admin, $t1, ['amount' => '50', 'reason' => 'LiteTest FIN child', 'issue_date' => '1982-02-03']));
    $out['atomic_close'] = $try(fn () => $periods->close($a->admin, $p1));
    $locker->exec('UNLOCK TABLES');
    $pdo->exec('SET SESSION lock_wait_timeout = DEFAULT');
    $out['atomic_rows'] = [
        (int) $col("SELECT COUNT(*) FROM payments WHERE invoice_id = {$t1}"),
        $plain($col("SELECT paid_total FROM invoices WHERE id = {$t1}")),
        (int) $col("SELECT COUNT(*) FROM credit_notes WHERE invoice_id = {$t1}"),
        $col("SELECT next_sequence FROM credit_note_number_series WHERE organization_id = {$org}") === $cnMid,
        (string) $col("SELECT status FROM financial_periods WHERE id = {$p1}"),
        (int) $col("SELECT COUNT(*) FROM timeline_events WHERE (subject_type = 'invoice' AND subject_id = {$t1}) OR (subject_type = 'financial_period' AND subject_id = {$p1})"),
    ];

    // ---- P: close / reopen through the routes → one event each, with the actor
    $out['close_status'] = $a->request('POST', "/finance/periods/{$p1}/close")['status'];
    $out['reopen_status'] = $a->request('POST', "/finance/periods/{$p1}/reopen")['status'];
    $out['period_events'] = [$events('financial_period', $p1, 'period_closed'), $events('financial_period', $p1, 'period_reopened')];
    $out['period_actor'] = (int) $col("SELECT COUNT(*) FROM timeline_events WHERE subject_type = 'financial_period' AND subject_id = {$p1} AND actor_user_id = " . (int) $a->admin['id']);
    $out['periods_page'] = $text($a->request('GET', '/finance/periods')['body']);
    $out['reclose_refused'] = $a->request('POST', "/finance/periods/{$p1}/reopen")['status']; // already open → refused, no event
    $out['period_events_after'] = (int) $col("SELECT COUNT(*) FROM timeline_events WHERE subject_type = 'financial_period' AND subject_id = {$p1}");
} catch (RuntimeException $e) {
    if ($e->getMessage() !== 'skip') {
        throw $e;
    }
} finally {
    if ($locker !== null) {
        $locker->exec('UNLOCK TABLES');
    }
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $pdo->exec('SET SESSION lock_wait_timeout = DEFAULT');
    foreach ($usedKeys as $usedKey) {
        $pdo->prepare('DELETE FROM finance_submission_keys WHERE organization_id = ? AND submission_key = ?')->execute([$org, $usedKey]);
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
    foreach ($periodIds as $p) {
        $pdo->exec("DELETE FROM timeline_events WHERE subject_type = 'financial_period' AND subject_id = {$p}");
        $pdo->exec("DELETE FROM financial_periods WHERE id = {$p}");
    }
    if ($roundingBefore !== false) {
        $pdo->prepare('UPDATE financial_profiles SET invoice_final_rounding = ? WHERE organization_id = ?')->execute([(int) $roundingBefore, $org]);
    }
    // Number series back to their values before this run — only when no remaining document uses a number at or above it.
    foreach ([['invoice_number_series', 'invoices', 'invoice_number', $invSeriesBefore], ['credit_note_number_series', 'credit_notes', 'credit_note_number', $cnSeriesBefore]] as [$series, $table, $column, $before]) {
        if ($before === false) {
            continue;
        }
        $pdo->prepare("UPDATE {$series} s SET s.next_sequence = :before
            WHERE s.organization_id = :org AND s.next_sequence > :before2
              AND NOT EXISTS (SELECT 1 FROM {$table} d WHERE d.organization_id = s.organization_id AND d.{$column} LIKE CONCAT(s.prefix, '%')
                  AND CAST(SUBSTRING(d.{$column}, CHAR_LENGTH(s.prefix) + 1) AS UNSIGNED) >= :before3)")
            ->execute(['before' => (int) $before, 'before2' => (int) $before, 'before3' => (int) $before, 'org' => $org]);
    }
    $out['series_restored'] = [
        $col("SELECT next_sequence FROM invoice_number_series WHERE organization_id = {$org}") === $invSeriesBefore,
        $col("SELECT next_sequence FROM credit_note_number_series WHERE organization_id = {$org}") === $cnSeriesBefore,
    ];
    $out['rounding_restored'] = $col("SELECT invoice_final_rounding FROM financial_profiles WHERE organization_id = {$org}") === $roundingBefore;
    $out['legacy_unchanged'] = $legacyHash() === $legacyBefore;
    $out['leftover'] = (int) $col("SELECT (SELECT COUNT(*) FROM invoices WHERE notes = 'LiteTest FIN child') + (SELECT COUNT(*) FROM credit_notes WHERE reason = 'LiteTest FIN child') + (SELECT COUNT(*) FROM financial_periods WHERE starts_on = '1983-01-01')");
}
echo json_encode($out, JSON_UNESCAPED_UNICODE);
