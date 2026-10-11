<?php
// Stage 5 (docs/project-finance-design.md): real two-connection checks of the budget
// replace, which the single-process lite suite can't exercise. crm_local only
// (LocalApp refuses anything else).
//
// Each scenario commits a temporary project (and its starting budget); THIS process
// replaces the budget inside an open transaction, a child process (budget-child.php)
// sends the competing request through the real router, and this process commits after 2 s.
//   save-save      budget O; A is being saved while B is submitted
//                  → B waits for A, then replaces it whole: final = B exactly, 2 timeline entries
//   replace-empty  no budget yet; A is being saved while B is submitted
//                  → one whole submission wins (never a mix, never a raw database error)
//   revert         budget O; A is being saved while a stale form re-submits O
//                  → O waits, sees A (locked read) and records the change: final = O, 2 entries
//   read-during    budget O; A is being saved while someone opens the budget form
//                  → the reader does not wait and sees O complete (never A, never a mix)
// Every temporary row (and the Projects module row, if this script added it) is deleted in finally.
// Usage: php tests/concurrency/budget-probe.php [save-save|replace-empty|revert|read-during ...]
$root = dirname(__DIR__, 2);
require $root . '/app/autoload.php';
require $root . '/tests/lite/Assert.php';
require $root . '/tests/lite/Support/LocalApp.php';

use Modules\Finance\BudgetRepository;
use Modules\Finance\ExpenseRepository;
use Modules\Finance\Services\BudgetService;
use Modules\Finance\Services\ExpenseService;

$a = Tests\Lite\Support\LocalApp::boot();
$a->rollBack();                      // autocommit: the service opens and commits its own transaction
$a->locale('en');
$pdo = $a->pdo;
$c = $a->container();
$engine = $c->make(App\Infrastructure\Authorization\AuthorizationEngine::class);
$timeline = $c->make(App\Application\Services\TimelineService::class);
$registry = $c->make(App\Infrastructure\Records\RecordTypeRegistry::class);
$svc = new BudgetService(new BudgetRepository(), $engine, $timeline, new ExpenseRepository(),
    new ExpenseService(new ExpenseRepository(), $engine, $timeline, $c->make(App\Application\Services\AttachmentService::class), $registry), $registry);
$admin = (int) $a->admin['id'];
$cur = (string) (int) $pdo->query('SELECT id FROM currencies WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();

$added = [];
$moduleId = (int) $pdo->query("SELECT id FROM modules WHERE `key` = 'projects'")->fetchColumn();
if ($moduleId === 0) {
    $pdo->exec("INSERT INTO modules (`key`, name, version, type, is_core, status, dependencies) VALUES ('projects', 'Projects', '0.1.0', 'addon', 0, 'active', '[]')");
    $moduleId = (int) $pdo->lastInsertId();
    $added['module'] = $moduleId;
}
if ((int) $pdo->query("SELECT COUNT(*) FROM organization_modules WHERE organization_id = {$a->orgId} AND module_id = {$moduleId}")->fetchColumn() === 0) {
    $pdo->exec("INSERT INTO organization_modules (organization_id, module_id, enabled) VALUES ({$a->orgId}, {$moduleId}, 1)");
    $added['entitlement'] = $moduleId;
}

$projects = [];
$newProject = function () use ($pdo, $a, $admin, &$projects): int {
    $pdo->prepare("INSERT INTO projects (organization_id, code, name, status, owner_id, created_by_user_id) VALUES (?, ?, 'CONCURRENCY-PROBE', 'active', ?, ?)")
        ->execute([$a->orgId, 'PROBE-' . bin2hex(random_bytes(3)), $admin, $admin]);

    return $projects[] = (int) $pdo->lastInsertId();
};
$l = fn (string $category, string $amount): array => ['category' => $category, 'currency_id' => $cur, 'amount' => $amount, 'notes' => ''];
$O = [$l('labor', '100')];
$A = [$l('labor', '200'), $l('services', '50')];
$B = [$l('labor', '300')];
$save = fn (int $p, array $lines) => $svc->replace($a->admin, $p, ['lines' => $lines]);
$run = function (callable $first, array $childArgs) use ($pdo): string {
    $pdo->beginTransaction();
    $first();
    $proc = proc_open([PHP_BINARY, __DIR__ . '/budget-child.php', ...$childArgs], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    usleep(2000000);
    $pdo->commit();
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    proc_close($proc);

    return trim($out);
};
$state = function (int $p) use ($pdo): string {
    $lines = $pdo->query("SELECT CONCAT(category, '=', TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM amount))) FROM finance_budget_lines WHERE project_id = {$p} ORDER BY category")->fetchAll(\PDO::FETCH_COLUMN);
    $events = (int) $pdo->query("SELECT COUNT(*) FROM timeline_events WHERE subject_type = 'project' AND subject_id = {$p} AND type = 'budget_updated'")->fetchColumn();

    return 'final=[' . implode(', ', $lines) . "] | budget_updated entries={$events}";
};

$scenarios = array_slice($argv, 1) ?: ['save-save', 'replace-empty', 'revert', 'read-during'];
try {
    foreach ($scenarios as $s) {
        $p = $newProject();
        switch ($s) {
            case 'save-save':
                $save($p, $O); // committed
                $out = $run(fn () => $save($p, $A), ['save', (string) $p, base64_encode(json_encode($B))]);
                break;
            case 'replace-empty':
                $out = $run(fn () => $save($p, $A), ['save', (string) $p, base64_encode(json_encode($B))]);
                break;
            case 'revert':
                $save($p, $O); // committed
                $out = $run(fn () => $save($p, $A), ['save', (string) $p, base64_encode(json_encode($O))]);
                break;
            case 'read-during':
                $save($p, $O); // committed
                $out = $run(fn () => $save($p, $A), ['read', (string) $p]);
                break;
            default:
                exit("unknown scenario {$s}\n");
        }
        printf("%-14s child=%s\n               %s\n", $s, $out, $state($p));
    }
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    foreach ($projects as $p) {
        $pdo->exec("DELETE FROM finance_budget_lines WHERE project_id = {$p} AND organization_id = {$a->orgId}");
        $pdo->exec("DELETE FROM timeline_events WHERE subject_type = 'project' AND subject_id = {$p}");
        $pdo->exec("DELETE FROM projects WHERE id = {$p} AND name = 'CONCURRENCY-PROBE'");
    }
    if (isset($added['entitlement'])) {
        $pdo->exec("DELETE FROM organization_modules WHERE organization_id = {$a->orgId} AND module_id = {$added['entitlement']}");
    }
    if (isset($added['module'])) {
        $pdo->exec("DELETE FROM modules WHERE id = {$added['module']}");
    }
}
echo 'leftover rows: ', (int) $pdo->query("SELECT (SELECT COUNT(*) FROM projects WHERE name = 'CONCURRENCY-PROBE') + (SELECT COUNT(*) FROM finance_budget_lines)")->fetchColumn(),
    ', projects module row ', isset($added['module']) ? 'added and removed' : 'was already there', "\n";
