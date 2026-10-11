<?php
// Child process for project-revenue-probe.php: ONE real request through the router.
// Usage: php project-revenue-child.php attach <projectId> <invoiceId>
//        php project-revenue-child.php detach <projectId> <invoiceId>
$root = dirname(__DIR__, 2);
require $root . '/app/autoload.php';
require $root . '/tests/lite/Assert.php';
require $root . '/tests/lite/Support/LocalApp.php';
$a = Tests\Lite\Support\LocalApp::boot();
$a->rollBack();                      // no outer transaction, as in production
$a->locale('en');
[$action, $project, $invoice] = [$argv[1], (int) $argv[2], (int) $argv[3]];
[$path, $body] = $action === 'attach'
    ? ["/finance/projects/{$project}/invoices", ['invoice_id' => (string) $invoice]]
    : ["/finance/projects/{$project}/invoices/{$invoice}/detach", []];
$t = microtime(true);
try {
    $r = $a->request('POST', $path, [], $body);
    $flash = $_SESSION['_flash'] ?? $_SESSION['flash'] ?? null;
    $result = ['status' => $r['status'], 'flash' => $flash];
} catch (\Throwable $e) {
    $result = ['error' => get_class($e) . ': ' . $e->getMessage()];
}
echo json_encode($result + ['waited' => round(microtime(true) - $t, 2)], JSON_UNESCAPED_UNICODE);
