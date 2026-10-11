<?php
// Child process: a real cancel() in its own connection and its own transaction.
$root = dirname(__DIR__, 2);
require $root . '/app/autoload.php';
require $root . '/tests/lite/Assert.php';
require $root . '/tests/lite/Support/LocalApp.php';
$a = Tests\Lite\Support\LocalApp::boot();
$a->rollBack();                      // no outer transaction: cancel() opens and commits its own, as in production
$a->locale('en');
$t = microtime(true);
try {
    $a->container()->make(Modules\Finance\Services\InvoiceService::class)->cancel($a->admin, (int) $argv[1], 'concurrency probe');
    $r = 'CANCELLED';
} catch (App\Core\Exceptions\ValidationException $e) {
    $r = 'REFUSED: ' . json_encode($e->errors, JSON_UNESCAPED_UNICODE);
}
echo json_encode(['result' => $r, 'waited' => round(microtime(true) - $t, 2)]);
