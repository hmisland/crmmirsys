<?php
// Stage 3-B (docs/project-finance-design.md): real two-connection checks of the
// invoice ↔ project attribution, which the single-process lite suite can't exercise.
// crm_local only (LocalApp refuses anything else).
//
// Each scenario commits a temporary project (+ invoice); THIS process does the first
// action inside an open transaction, a child process (project-revenue-child.php)
// sends the competing request through the real router, and this process commits after 2 s.
//   attach-same    same invoice → same project twice     → one link, one timeline entry per side
//   attach-other   same invoice → two different projects → one project only (no move, no 2nd row)
//   issue-detach   the invoice is issued while a detach runs → detach refused, link kept
//   detach-detach  detach twice                         → link gone, ONE "detached" entry per side
// Every temporary row (and the Projects module row, if this script added it) is deleted in finally.
// Usage: php tests/concurrency/project-revenue-probe.php [attach-same|attach-other|issue-detach|detach-detach ...]
$root = dirname(__DIR__, 2);
require $root . '/app/autoload.php';
require $root . '/tests/lite/Assert.php';
require $root . '/tests/lite/Support/LocalApp.php';

use Modules\Finance\FinanceDocumentLinkRepository;
use Modules\Finance\InvoiceRepository;
use Modules\Finance\ProjectRevenueRepository;
use Modules\Finance\Services\ProjectRevenueService;

$a = Tests\Lite\Support\LocalApp::boot();
$a->rollBack();
$a->locale('en');
$pdo = $a->pdo;
$c = $a->container();
$svc = new ProjectRevenueService(new ProjectRevenueRepository(), new FinanceDocumentLinkRepository(), new InvoiceRepository(),
    $c->make(App\Infrastructure\Authorization\AuthorizationEngine::class), $c->make(App\Application\Services\TimelineService::class),
    $c->make(App\Infrastructure\Records\RecordTypeRegistry::class));
$admin = (int) $a->admin['id'];
$currency = (int) $pdo->query('SELECT id FROM currencies WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();

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

$made = ['projects' => [], 'invoices' => []];
$newProject = function () use ($pdo, $a, $admin, &$made): int {
    $pdo->prepare("INSERT INTO projects (organization_id, code, name, status, owner_id, created_by_user_id) VALUES (?, ?, 'CONCURRENCY-PROBE', 'active', ?, ?)")
        ->execute([$a->orgId, 'PROBE-' . bin2hex(random_bytes(3)), $admin, $admin]);

    return $made['projects'][] = (int) $pdo->lastInsertId();
};
$newInvoice = function (string $status) use ($pdo, $a, $admin, $currency, &$made): int {
    $pdo->prepare("INSERT INTO invoices (organization_id, owner_id, issue_date, currency_id, subtotal, grand_total, status, notes) VALUES (?, ?, CURDATE(), ?, 100, 100, ?, 'CONCURRENCY-PROBE')")
        ->execute([$a->orgId, $admin, $currency, $status]);

    return $made['invoices'][] = (int) $pdo->lastInsertId();
};
$run = function (callable $first, string $action, int $project, int $invoice) use ($pdo): string {
    $pdo->beginTransaction();
    $first();
    $proc = proc_open([PHP_BINARY, __DIR__ . '/project-revenue-child.php', $action, (string) $project, (string) $invoice], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    usleep(2000000);
    $pdo->commit();
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    proc_close($proc);

    return trim($out);
};
$state = function (int $invoice, array $projects) use ($pdo): string {
    $n = fn (string $sql) => (int) $pdo->query($sql)->fetchColumn();
    $linked = $pdo->query("SELECT GROUP_CONCAT(linked_id) FROM finance_document_links WHERE document_type = 'invoice' AND document_id = {$invoice} AND linked_type = 'project'")->fetchColumn();
    $ids = implode(',', $projects);

    return sprintf('project links=[%s] status=%s | project attributed=%d detached=%d | invoice attributed=%d detached=%d',
        (string) $linked, $pdo->query("SELECT status FROM invoices WHERE id = {$invoice}")->fetchColumn(),
        $n("SELECT COUNT(*) FROM timeline_events WHERE subject_type = 'project' AND subject_id IN ({$ids}) AND type = 'invoice_attributed'"),
        $n("SELECT COUNT(*) FROM timeline_events WHERE subject_type = 'project' AND subject_id IN ({$ids}) AND type = 'invoice_detached'"),
        $n("SELECT COUNT(*) FROM timeline_events WHERE subject_type = 'invoice' AND subject_id = {$invoice} AND type = 'project_attributed'"),
        $n("SELECT COUNT(*) FROM timeline_events WHERE subject_type = 'invoice' AND subject_id = {$invoice} AND type = 'project_detached'"));
};

$scenarios = array_slice($argv, 1) ?: ['attach-same', 'attach-other', 'issue-detach', 'detach-detach'];
try {
    foreach ($scenarios as $s) {
        $p = $newProject();
        switch ($s) {
            case 'attach-same':
                $i = $newInvoice('issued');
                $out = $run(fn () => $svc->attach($a->admin, $p, $i), 'attach', $p, $i);
                $projects = [$p];
                break;
            case 'attach-other':
                $i = $newInvoice('issued');
                $q = $newProject();
                $out = $run(fn () => $svc->attach($a->admin, $p, $i), 'attach', $q, $i);
                $projects = [$p, $q];
                break;
            case 'issue-detach':
                $i = $newInvoice('draft');
                $svc->attach($a->admin, $p, $i); // committed
                $out = $run(function () use ($pdo, $i): void {
                    $pdo->query("SELECT id FROM invoices WHERE id = {$i} FOR UPDATE")->fetchAll();
                    $pdo->exec("UPDATE invoices SET status = 'issued' WHERE id = {$i}"); // what issue() writes on this row
                }, 'detach', $p, $i);
                $projects = [$p];
                break;
            case 'detach-detach':
                $i = $newInvoice('draft');
                $svc->attach($a->admin, $p, $i); // committed
                $out = $run(fn () => $svc->detach($a->admin, $p, $i), 'detach', $p, $i);
                $projects = [$p];
                break;
        }
        printf("%-13s child=%s\n              %s\n", $s, $out, $state($i, $projects));
    }
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    foreach ($made['invoices'] as $i) {
        $pdo->exec("DELETE FROM finance_document_links WHERE document_type = 'invoice' AND document_id = {$i}");
        $pdo->exec("DELETE FROM timeline_events WHERE subject_type = 'invoice' AND subject_id = {$i}");
        $pdo->exec("DELETE FROM invoices WHERE id = {$i} AND notes = 'CONCURRENCY-PROBE'");
    }
    foreach ($made['projects'] as $p) {
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
echo 'leftover rows: ', (int) $pdo->query("SELECT (SELECT COUNT(*) FROM projects WHERE name = 'CONCURRENCY-PROBE') + (SELECT COUNT(*) FROM invoices WHERE notes = 'CONCURRENCY-PROBE') + (SELECT COUNT(*) FROM finance_document_links WHERE linked_type = 'project')")->fetchColumn(),
    ', projects module row ', isset($added['module']) ? 'added and removed' : 'was already there', "\n";
