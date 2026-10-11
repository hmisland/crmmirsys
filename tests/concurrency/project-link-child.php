<?php
// Child process for project-link-probe.php: ONE real request through the router
// (real ProjectsModule wiring, own connection, own transaction).
// Usage: php project-link-child.php link <projectId> <contractId>
//        php project-link-child.php unlink <projectId> <linkId>
$root = dirname(__DIR__, 2);
require $root . '/app/autoload.php';
require $root . '/tests/lite/Assert.php';
require $root . '/tests/lite/Support/LocalApp.php';
$a = Tests\Lite\Support\LocalApp::boot();
$a->rollBack();                      // no outer transaction, as in production
$a->locale('en');
[$action, $project, $other] = [$argv[1], (int) $argv[2], (int) $argv[3]];
[$path, $body] = $action === 'link'
    ? ['/projects/links', ['project_id' => (string) $project, 'linked_type' => 'contract', 'linked_id' => (string) $other]]
    : ["/projects/{$project}/links/{$other}/delete", []];
$t = microtime(true);
try {
    $r = $a->request('POST', $path, [], $body);
    $result = ['status' => $r['status'], 'location' => $r['location']];
} catch (\Throwable $e) {
    $result = ['error' => get_class($e) . ': ' . $e->getMessage()];
}
echo json_encode($result + ['waited' => round(microtime(true) - $t, 2)]);
