<?php
// Child process for installment-lock-probe.php: ONE real request through the
// router (real FinanceModule wiring, own connection, own transaction).
// Usage: php installment-child.php <doc|void|edit|convert|add> <id> [currencyId]
$root = dirname(__DIR__, 2);
require $root . '/app/autoload.php';
require $root . '/tests/lite/Assert.php';
require $root . '/tests/lite/Support/LocalApp.php';
$a = Tests\Lite\Support\LocalApp::boot();
$a->rollBack();                      // no outer transaction: the services open and commit their own, as in production
$a->locale('en');
[$action, $id] = [$argv[1], (int) $argv[2]];
$currency = (string) ($argv[3] ?? '');
$doc = ['currency_id' => $currency, 'pricing_mode' => 'tax_exclusive', 'issue_date' => date('Y-m-d'), 'tax_profile_id' => '',
    'items' => [['description' => 'CONCURRENCY-PROBE line', 'quantity' => '1', 'unit_price' => '10', 'discount_type' => 'none', 'discount_value' => '0']]];
[$method, $path, $body] = match ($action) {
    'doc' => ['POST', '/finance/invoices', $doc + ['source_type' => 'installment', 'source_id' => (string) $id]],
    'void' => ['POST', "/finance/installments/{$id}/void", ['reason' => 'concurrency probe']],
    'edit' => ['POST', "/finance/installments/{$id}", ['due_date' => '2031-01-01', 'amount' => '11']],
    'convert' => ['POST', "/finance/proformas/{$id}/convert", []],
    'add' => ['POST', "/finance/contracts/{$id}/installments", ['due_date' => '2031-01-01', 'amount' => '10', 'currency_id' => $currency]],
};
$t = microtime(true);
$r = $a->request($method, $path, [], $body);
echo json_encode(['status' => $r['status'], 'location' => $r['location'], 'waited' => round(microtime(true) - $t, 2)]);
