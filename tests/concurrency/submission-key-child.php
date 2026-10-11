<?php
// Child process for submission-key-probe.php: one submission through the real service, own transaction.
// Usage: php submission-key-child.php expense <key> | payment <invoiceId> <key> | credit <invoiceId> <key>
$root = dirname(__DIR__, 2);
require $root . '/app/autoload.php';
require $root . '/tests/lite/Assert.php';
require $root . '/tests/lite/Support/LocalApp.php';

$a = Tests\Lite\Support\LocalApp::boot();
$a->rollBack();
$a->locale('en');
$c = $a->container();
$auth = $c->make(App\Infrastructure\Authorization\AuthorizationEngine::class);
$periods = new Modules\Finance\Services\FinancialPeriodService(new Modules\Finance\FinancialPeriodRepository(), $auth);
$irr = (string) $a->pdo->query("SELECT id FROM currencies WHERE code = 'IRR'")->fetchColumn();
$t = microtime(true);
try {
    $r = match ($argv[1]) {
        'expense' => (int) (new Modules\Finance\Services\ExpenseService(new Modules\Finance\ExpenseRepository(), $auth, $c->make(App\Application\Services\TimelineService::class),
            $c->make(App\Application\Services\AttachmentService::class), $c->make(App\Infrastructure\Records\RecordTypeRegistry::class)))
            ->create($a->admin, ['category' => 'other', 'description' => 'KEY-PROBE', 'expense_date' => date('Y-m-d'), 'amount' => '9', 'currency_id' => $irr, 'submission_key' => $argv[2]])['id'],
        'payment' => (new Modules\Finance\Services\PaymentService(new Modules\Finance\PaymentRepository(), new Modules\Finance\InvoiceRepository(), new Modules\Finance\CreditNoteRepository(), $auth, $periods))
            ->record($a->admin, (int) $argv[2], ['amount' => '10', 'payment_method' => 'cash', 'payment_date' => date('Y-m-d'), 'submission_key' => $argv[3]])['payment_id'],
        'credit' => (new Modules\Finance\Services\CreditNoteService(new Modules\Finance\CreditNoteRepository(), new Modules\Finance\CreditNoteNumberSeriesRepository(), new Modules\Finance\InvoiceRepository(), $auth, $periods))
            ->issue($a->admin, (int) $argv[2], ['amount' => '10', 'reason' => 'KEY-PROBE', 'issue_date' => date('Y-m-d'), 'submission_key' => $argv[3]])['id'],
    };
    $out = ['result' => 'OK', 'id' => (int) $r];
} catch (App\Core\Exceptions\ValidationException $e) {
    $out = ['result' => 'REFUSED', 'detail' => $e->errors];
} catch (\Throwable $e) {
    $out = ['result' => 'ERROR', 'detail' => get_class($e) . ': ' . $e->getMessage()];
}
echo json_encode($out + ['waited' => round(microtime(true) - $t, 2)], JSON_UNESCAPED_UNICODE);
