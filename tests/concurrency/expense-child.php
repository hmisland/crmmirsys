<?php
// Child process for expense-probe.php: ONE real request through the router.
// Usage: php expense-child.php correct <originalId> <amount>
//        php expense-child.php create <expenseDate>
//        php expense-child.php update <expenseId>
//        php expense-child.php close <periodId>
$root = dirname(__DIR__, 2);
require $root . '/app/autoload.php';
require $root . '/tests/lite/Assert.php';
require $root . '/tests/lite/Support/LocalApp.php';
$a = Tests\Lite\Support\LocalApp::boot();
$a->rollBack();                      // no outer transaction, as in production
$a->locale('en');
$currency = (string) (int) $a->pdo->query('SELECT id FROM currencies WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
$expense = fn (array $extra = []) => $extra + [
    'category' => 'other', 'description' => 'CONCURRENCY-PROBE', 'reference_number' => '',
    'expense_date' => date('Y-m-d'), 'amount' => '10', 'currency_id' => $currency,
];
[$action, $arg] = [$argv[1], $argv[2]];
[$path, $body] = match ($action) {
    'correct' => ["/finance/expenses/{$arg}/corrections", $expense(['correction_direction' => 'decrease', 'amount' => $argv[3]])],
    'create' => ['/finance/expenses', $expense(['expense_date' => $arg])],
    'update' => ["/finance/expenses/{$arg}", $expense(['description' => 'CONCURRENCY-PROBE edited'])],
    'close' => ["/finance/periods/{$arg}/close", []],
};
$t = microtime(true);
try {
    $r = $a->request('POST', $path, [], $body);
    $errors = $_SESSION['_errors'] ?? $_SESSION['errors'] ?? $_SESSION['_flash'] ?? $_SESSION['flash'] ?? null;
    $result = ['status' => $r['status'], 'location' => $r['location'] ?? null, 'session' => $errors];
} catch (\Throwable $e) {
    $result = ['error' => get_class($e) . ': ' . $e->getMessage()];
}
echo json_encode($result + ['waited' => round(microtime(true) - $t, 2)], JSON_UNESCAPED_UNICODE);
