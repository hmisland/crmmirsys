<?php
// Stage 3-A (docs/project-finance-design.md): real two-connection checks of the
// contract ↔ project link, which the single-process lite suite can't exercise.
// crm_local only (LocalApp refuses anything else).
//
// Each scenario commits a temporary contract + project, THIS process does the
// first action inside an open transaction, a child process
// (project-link-child.php) sends the competing request through the real
// router, and this process commits after 2 s.
//   link-link      the same pair linked twice → one row, one "linked" event per side,
//                  child gets a normal response (no raw duplicate-key error)
//   unlink-unlink  the same link removed twice → row gone, ONE "unlinked" event per side
//   unlink-link    unlink racing a link of the same pair → consistent end state:
//                  rows = linked events − unlinked events, no error
// Every temporary row (and module rows this script added) is deleted in finally.
// Usage: php tests/concurrency/project-link-probe.php [link-link|unlink-unlink|unlink-link ...]
$root = dirname(__DIR__, 2);
require $root . '/app/autoload.php';
require $root . '/tests/lite/Assert.php';
require $root . '/tests/lite/Support/LocalApp.php';

$a = Tests\Lite\Support\LocalApp::boot();
$a->rollBack();
$a->locale('en');
$pdo = $a->pdo;
$svc = $a->container()->make(Modules\Projects\Services\ProjectService::class);
$admin = (int) $a->admin['id'];

// Projects + Contracts installed and entitled (committed) for the child; remember what we add.
$added = [];
foreach (['projects' => 'Projects', 'contracts' => 'Contracts'] as $key => $name) {
    $id = (int) $pdo->query("SELECT id FROM modules WHERE `key` = '{$key}'")->fetchColumn();
    if ($id === 0) {
        $pdo->exec("INSERT INTO modules (`key`, name, version, type, is_core, status, dependencies) VALUES ('{$key}', '{$name}', '0.1.0', 'addon', 0, 'active', '[]')");
        $id = (int) $pdo->lastInsertId();
        $added['module'][] = $id;
    }
    if ((int) $pdo->query("SELECT COUNT(*) FROM organization_modules WHERE organization_id = {$a->orgId} AND module_id = {$id}")->fetchColumn() === 0) {
        $pdo->exec("INSERT INTO organization_modules (organization_id, module_id, enabled) VALUES ({$a->orgId}, {$id}, 1)");
        $added['entitlement'][] = $id;
    }
}

$made = ['contracts' => [], 'projects' => []];
$pair = function () use ($pdo, $a, $admin, &$made): array {
    $pdo->prepare("INSERT INTO contracts (organization_id, title, owner_id, status, created_by_user_id) VALUES (?, 'CONCURRENCY-PROBE', ?, 'active', ?)")->execute([$a->orgId, $admin, $admin]);
    $c = $made['contracts'][] = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO projects (organization_id, code, name, status, owner_id, created_by_user_id) VALUES (?, ?, 'CONCURRENCY-PROBE', 'active', ?, ?)")->execute([$a->orgId, 'PROBE-' . bin2hex(random_bytes(3)), $admin, $admin]);
    $p = $made['projects'][] = (int) $pdo->lastInsertId();

    return [$p, $c];
};
$run = function (callable $first, string $action, int $project, int $other) use ($pdo): string {
    $pdo->beginTransaction();
    $first();                                         // uncommitted: holds the row / key lock
    $proc = proc_open([PHP_BINARY, __DIR__ . '/project-link-child.php', $action, (string) $project, (string) $other], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    usleep(2000000);
    $pdo->commit();
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    proc_close($proc);

    return trim($out);
};
$state = function (int $p, int $c) use ($pdo): string {
    $n = fn (string $sql) => (int) $pdo->query($sql)->fetchColumn();

    return sprintf('rows=%d | project linked=%d unlinked=%d | contract linked=%d unlinked=%d',
        $n("SELECT COUNT(*) FROM project_links WHERE project_id = {$p} AND linked_type = 'contract' AND linked_id = {$c}"),
        $n("SELECT COUNT(*) FROM timeline_events WHERE subject_type = 'project' AND subject_id = {$p} AND type = 'linked'"),
        $n("SELECT COUNT(*) FROM timeline_events WHERE subject_type = 'project' AND subject_id = {$p} AND type = 'unlinked'"),
        $n("SELECT COUNT(*) FROM timeline_events WHERE subject_type = 'contract' AND subject_id = {$c} AND type = 'project_linked'"),
        $n("SELECT COUNT(*) FROM timeline_events WHERE subject_type = 'contract' AND subject_id = {$c} AND type = 'project_unlinked'"));
};
$linkIdOf = fn (int $p, int $c): int => (int) $pdo->query("SELECT id FROM project_links WHERE project_id = {$p} AND linked_type = 'contract' AND linked_id = {$c}")->fetchColumn();

$scenarios = array_slice($argv, 1) ?: ['link-link', 'unlink-unlink', 'unlink-link'];
try {
    foreach ($scenarios as $s) {
        [$p, $c] = $pair();
        switch ($s) {
            case 'link-link':
                $out = $run(fn () => $svc->link($a->admin, $p, 'contract', $c), 'link', $p, $c);
                break;
            case 'unlink-unlink':
                $svc->link($a->admin, $p, 'contract', $c); // committed
                $id = $linkIdOf($p, $c);
                $out = $run(fn () => $svc->unlink($a->admin, $p, $id), 'unlink', $p, $id);
                break;
            case 'unlink-link':
                $svc->link($a->admin, $p, 'contract', $c); // committed
                $id = $linkIdOf($p, $c);
                $out = $run(fn () => $svc->unlink($a->admin, $p, $id), 'link', $p, $c);
                break;
        }
        printf("%-14s child=%s\n               %s\n", $s, $out, $state($p, $c));
    }
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    foreach ($made['projects'] as $p) {
        $pdo->exec("DELETE FROM project_links WHERE project_id = {$p}");
        $pdo->exec("DELETE FROM timeline_events WHERE subject_type = 'project' AND subject_id = {$p}");
        $pdo->exec("DELETE FROM projects WHERE id = {$p} AND name = 'CONCURRENCY-PROBE'");
    }
    foreach ($made['contracts'] as $c) {
        $pdo->exec("DELETE FROM timeline_events WHERE subject_type = 'contract' AND subject_id = {$c}");
        $pdo->exec("DELETE FROM contracts WHERE id = {$c} AND title = 'CONCURRENCY-PROBE'");
    }
    foreach ($added['entitlement'] ?? [] as $id) {
        $pdo->exec("DELETE FROM organization_modules WHERE organization_id = {$a->orgId} AND module_id = {$id}");
    }
    foreach ($added['module'] ?? [] as $id) {
        $pdo->exec("DELETE FROM modules WHERE id = {$id}");
    }
}
echo 'leftover rows: ', (int) $pdo->query("SELECT (SELECT COUNT(*) FROM projects WHERE name = 'CONCURRENCY-PROBE') + (SELECT COUNT(*) FROM contracts WHERE title = 'CONCURRENCY-PROBE')")->fetchColumn(),
    ', module rows added and removed: ', count($added['module'] ?? []), "\n";
