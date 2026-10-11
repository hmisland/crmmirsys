<?php
// Child process for budget-probe.php: ONE real request through the router.
// Usage: php budget-child.php save <projectId> <base64 JSON lines>
//        php budget-child.php read <projectId>
$root = dirname(__DIR__, 2);
require $root . '/app/autoload.php';
require $root . '/tests/lite/Assert.php';
require $root . '/tests/lite/Support/LocalApp.php';
$a = Tests\Lite\Support\LocalApp::boot();
$a->rollBack();                      // no outer transaction, as in production
$a->locale('en');
[$action, $project] = [$argv[1], (int) $argv[2]];
$t = microtime(true);
try {
    if ($action === 'save') {
        $r = $a->request('POST', "/finance/projects/{$project}/budget", [], ['lines' => json_decode(base64_decode($argv[3]), true)]);
        $result = ['status' => $r['status'], 'flash' => $_SESSION['_flash'] ?? $_SESSION['flash'] ?? null];
    } else {
        // What a reader sees mid-replace: the amounts in the budget form (one consistent snapshot).
        $r = $a->request('GET', "/finance/projects/{$project}/budget");
        preg_match_all('/name="lines\[\d+\]\[amount\]" value="([^"]+)"/', $r['body'], $m);
        $result = ['status' => $r['status'], 'amounts' => $m[1]];
    }
} catch (\Throwable $e) {
    $result = ['error' => get_class($e) . ': ' . $e->getMessage()];
}
echo json_encode($result + ['waited' => round(microtime(true) - $t, 2)], JSON_UNESCAPED_UNICODE);
