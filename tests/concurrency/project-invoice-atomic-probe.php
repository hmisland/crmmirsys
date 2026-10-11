<?php
// Stage 3-C (C1): the invoice and its project link are written in ONE transaction.
// The lite suite runs inside one outer transaction, so it can't see a real
// rollback; this probe runs against crm_local with autocommit, like production.
//
// It makes the link insert fail on purpose: the next invoice id is predicted
// (a committed dummy invoice is inserted and deleted — InnoDB never reuses that
// id), and a committed project link for that id already exists, so the new
// invoice's link hits the UNIQUE key. Expected: the request fails, and NO
// invoice row with that id remains. Then a normal create is checked as well.
// Every temporary row (and the Projects module row, if this script added it)
// is deleted in finally.
// Usage: php tests/concurrency/project-invoice-atomic-probe.php
$root = dirname(__DIR__, 2);
require $root . '/app/autoload.php';
require $root . '/tests/lite/Assert.php';
require $root . '/tests/lite/Support/LocalApp.php';

$a = Tests\Lite\Support\LocalApp::boot();
$a->rollBack();                      // autocommit: the service opens and commits its own transaction
$a->locale('en');
$pdo = $a->pdo;
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

$projects = [];
$invoiceIds = [];
$newProject = function () use ($pdo, $a, $admin, &$projects): int {
    $pdo->prepare("INSERT INTO projects (organization_id, code, name, status, owner_id, created_by_user_id) VALUES (?, ?, 'CONCURRENCY-PROBE', 'active', ?, ?)")
        ->execute([$a->orgId, 'PROBE-' . bin2hex(random_bytes(3)), $admin, $admin]);

    return $projects[] = (int) $pdo->lastInsertId();
};
$body = fn (int $projectId) => [
    'currency_id' => (string) $currency, 'pricing_mode' => 'tax_exclusive', 'issue_date' => date('Y-m-d'), 'tax_profile_id' => '',
    'items' => [['description' => 'CONCURRENCY-PROBE line', 'quantity' => '1', 'unit_price' => '10', 'discount_type' => 'none', 'discount_value' => '0']],
    'source_type' => 'project', 'source_id' => (string) $projectId,
];

try {
    $p = $newProject();
    $blocker = $newProject();

    // 1) Forced failure of the link insert → the invoice must not survive.
    $pdo->prepare("INSERT INTO invoices (organization_id, owner_id, issue_date, currency_id, status, notes) VALUES (?, ?, CURDATE(), ?, 'draft', 'CONCURRENCY-PROBE')")->execute([$a->orgId, $admin, $currency]);
    $dummy = (int) $pdo->lastInsertId();
    $pdo->exec("DELETE FROM invoices WHERE id = {$dummy}");
    $next = $dummy + 1;
    $invoiceIds[] = $next;
    $pdo->prepare("INSERT INTO finance_document_links (organization_id, document_type, document_id, linked_type, linked_id, linked_label) VALUES (?, 'invoice', ?, 'project', ?, 'CONCURRENCY-PROBE')")->execute([$a->orgId, $next, $blocker]);
    try {
        $r = $a->request('POST', '/finance/invoices', [], $body($p));
        $outcome = 'status ' . $r['status'] . ' location ' . ($r['location'] ?? '-');
    } catch (\Throwable $e) {
        $outcome = 'failed: ' . get_class($e) . ' (' . (($e instanceof \PDOException) ? ($e->errorInfo[1] ?? '') : '') . ')';
    }
    $survived = (int) $pdo->query("SELECT COUNT(*) FROM invoices WHERE id = {$next}")->fetchColumn();
    $lines = (int) $pdo->query("SELECT COUNT(*) FROM invoice_items WHERE invoice_id = {$next}")->fetchColumn();
    $events = (int) $pdo->query("SELECT COUNT(*) FROM timeline_events WHERE subject_type = 'project' AND subject_id = {$p}")->fetchColumn();
    printf("forced link failure: request %s | invoice #%d rows=%d, its lines=%d, project timeline entries=%d  → %s\n",
        $outcome, $next, $survived, $lines, $events, $survived === 0 && $lines === 0 && $events === 0 ? 'ATOMIC' : 'NOT ATOMIC');

    // 2) Normal create → one invoice + one project link, committed.
    $r = $a->request('POST', '/finance/invoices', [], $body($p));
    $id = (int) basename((string) $r['location']);
    $invoiceIds[] = $id;
    printf("normal create: status %d | invoice #%d status=%s | project links=%d\n", $r['status'], $id,
        (string) $pdo->query("SELECT status FROM invoices WHERE id = {$id}")->fetchColumn(),
        (int) $pdo->query("SELECT COUNT(*) FROM finance_document_links WHERE document_type = 'invoice' AND document_id = {$id} AND linked_type = 'project' AND linked_id = {$p}")->fetchColumn());
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    foreach ($invoiceIds as $i) {
        $pdo->exec("DELETE FROM finance_document_links WHERE document_type = 'invoice' AND document_id = {$i}");
        $pdo->exec("DELETE FROM timeline_events WHERE subject_type = 'invoice' AND subject_id = {$i}");
        $pdo->exec("DELETE FROM invoices WHERE id = {$i}");
    }
    $pdo->exec("DELETE FROM invoices WHERE notes = 'CONCURRENCY-PROBE'");
    foreach ($projects as $p) {
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
echo 'leftover rows: ', (int) $pdo->query("SELECT (SELECT COUNT(*) FROM projects WHERE name = 'CONCURRENCY-PROBE') + (SELECT COUNT(*) FROM invoice_items WHERE description = 'CONCURRENCY-PROBE line') + (SELECT COUNT(*) FROM finance_document_links WHERE linked_type = 'project')")->fetchColumn(),
    ', projects module row ', isset($added['module']) ? 'added and removed' : 'was already there', "\n";
