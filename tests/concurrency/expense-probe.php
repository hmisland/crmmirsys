<?php
// Phase 4 — Expenses (docs/project-finance-design.md, D-P3 / E8): real two-connection
// checks the single-process lite suite can't exercise. crm_local only (LocalApp refuses
// anything else).
//
// Each scenario commits temporary periods / expenses; THIS process does the first action
// inside an open transaction, a child process (expense-child.php) sends the competing
// request through the real router, and this process commits after 2 s.
//   correct-correct  two decrease corrections of the same original (100 − 60 − 60)
//                    → the second waits on the original's row lock, then is refused (net 40)
//   create-close     an expense is being recorded while the period is closed
//                    → close() waits for the expense transaction (shared period-row lock)
//   close-create     the period close is pending while an expense is recorded in it
//                    → the expense waits, then sees "closed" and is refused
//   void-edit        an edit arrives while the expense is being voided
//                    → the edit waits, then is refused (no longer recorded)
// Every temporary row is deleted in finally.
// Usage: php tests/concurrency/expense-probe.php [correct-correct|create-close|close-create|void-edit ...]
$root = dirname(__DIR__, 2);
require $root . '/app/autoload.php';
require $root . '/tests/lite/Assert.php';
require $root . '/tests/lite/Support/LocalApp.php';

use Modules\Finance\ExpenseRepository;
use Modules\Finance\Services\ExpenseService;

$a = Tests\Lite\Support\LocalApp::boot();
$a->rollBack();                      // autocommit: the service opens and commits its own transaction
$a->locale('en');
$pdo = $a->pdo;
$c = $a->container();
$svc = new ExpenseService(new ExpenseRepository(), $c->make(App\Infrastructure\Authorization\AuthorizationEngine::class),
    $c->make(App\Application\Services\TimelineService::class), $c->make(App\Application\Services\AttachmentService::class),
    $c->make(App\Infrastructure\Records\RecordTypeRegistry::class));
$currency = (string) (int) $pdo->query('SELECT id FROM currencies WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
$input = fn (array $extra = []) => $extra + [
    'category' => 'other', 'description' => 'CONCURRENCY-PROBE', 'reference_number' => '',
    'expense_date' => date('Y-m-d'), 'amount' => '100', 'currency_id' => $currency,
];
$periods = [];
$period = function (string $year) use ($pdo, $a, &$periods): int {
    if ((int) $pdo->query("SELECT COUNT(*) FROM financial_periods WHERE organization_id = {$a->orgId} AND starts_on <= '{$year}-12-31' AND ends_on >= '{$year}-01-01'")->fetchColumn() > 0) {
        exit("a period already covers {$year}; probe not run\n");
    }
    $pdo->exec("INSERT INTO financial_periods (organization_id, starts_on, ends_on, status) VALUES ({$a->orgId}, '{$year}-01-01', '{$year}-12-31', 'open')");

    return $periods[] = (int) $pdo->lastInsertId();
};
$run = function (callable $first, array $childArgs) use ($pdo): string {
    $pdo->beginTransaction();
    $first();
    $proc = proc_open([PHP_BINARY, __DIR__ . '/expense-child.php', ...$childArgs], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    usleep(2000000);
    $pdo->commit();
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    proc_close($proc);

    return trim($out);
};
$one = fn (string $sql) => $pdo->query($sql)->fetchColumn();

$scenarios = array_slice($argv, 1) ?: ['correct-correct', 'create-close', 'close-create', 'void-edit'];
try {
    foreach ($scenarios as $s) {
        switch ($s) {
            case 'correct-correct':
                $p = $period('1996');
                $orig = (int) $svc->create($a->admin, $input(['expense_date' => '1996-06-01']))['id'];
                $pdo->exec("UPDATE financial_periods SET status = 'closed' WHERE id = {$p}");
                $out = $run(fn () => $svc->createCorrection($a->admin, $orig, ['correction_direction' => 'decrease'] + $input(['amount' => '60'])), ['correct', (string) $orig, '60']);
                $state = sprintf('recorded corrections=%d | net=%s',
                    (int) $one("SELECT COUNT(*) FROM finance_expenses WHERE correction_of_id = {$orig} AND status = 'recorded'"),
                    $svc->net($svc->find($a->admin, $orig), (new ExpenseRepository())->correctionsOf($orig)));
                break;
            case 'create-close':
                $p = $period('1997');
                $out = $run(fn () => $svc->create($a->admin, $input(['expense_date' => '1997-05-05'])), ['close', (string) $p]);
                $state = sprintf('expenses in 1997=%d | period=%s', (int) $one("SELECT COUNT(*) FROM finance_expenses WHERE expense_date = '1997-05-05' AND description = 'CONCURRENCY-PROBE'"),
                    (string) $one("SELECT status FROM financial_periods WHERE id = {$p}"));
                break;
            case 'close-create':
                $p = $period('1998');
                $out = $run(fn () => $pdo->exec("UPDATE financial_periods SET status = 'closed', closed_at = NOW() WHERE id = {$p}"), ['create', '1998-05-05']); // what close() writes
                $state = sprintf('expenses in 1998=%d | period=%s', (int) $one("SELECT COUNT(*) FROM finance_expenses WHERE expense_date = '1998-05-05' AND description LIKE 'CONCURRENCY-PROBE%'"),
                    (string) $one("SELECT status FROM financial_periods WHERE id = {$p}"));
                break;
            case 'void-edit':
                $x = (int) $svc->create($a->admin, $input())['id'];
                $out = $run(fn () => $svc->void($a->admin, $x, 'CONCURRENCY-PROBE'), ['update', (string) $x]);
                $state = sprintf('status=%s | description=%s', (string) $one("SELECT status FROM finance_expenses WHERE id = {$x}"), (string) $one("SELECT description FROM finance_expenses WHERE id = {$x}"));
                break;
            default:
                exit("unknown scenario {$s}\n");
        }
        printf("%-15s child=%s\n                %s\n", $s, $out, $state);
    }
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $ids = array_map('intval', $pdo->query("SELECT id FROM finance_expenses WHERE description LIKE 'CONCURRENCY-PROBE%'")->fetchAll(\PDO::FETCH_COLUMN));
    if ($ids !== []) {
        $list = implode(',', $ids);
        $pdo->exec("DELETE FROM timeline_events WHERE subject_type = 'expense' AND subject_id IN ({$list})");
        $pdo->exec("DELETE FROM finance_expenses WHERE id IN ({$list}) AND entry_type = 'correction'");
        $pdo->exec("DELETE FROM finance_expenses WHERE id IN ({$list})");
    }
    foreach ($periods as $p) {
        // Stage 5.2 (L4): closing through the route writes a period_closed audit event.
        $pdo->exec("DELETE FROM timeline_events WHERE subject_type = 'financial_period' AND subject_id = {$p}");
        $pdo->exec("DELETE FROM financial_periods WHERE id = {$p}");
    }
}
echo 'leftover rows: ', (int) $one("SELECT (SELECT COUNT(*) FROM finance_expenses WHERE description LIKE 'CONCURRENCY-PROBE%') + (SELECT COUNT(*) FROM financial_periods WHERE starts_on < '2000-01-01') + (SELECT COUNT(*) FROM timeline_events t WHERE t.subject_type = 'financial_period' AND NOT EXISTS (SELECT 1 FROM financial_periods f WHERE f.id = t.subject_id))"), "\n";
