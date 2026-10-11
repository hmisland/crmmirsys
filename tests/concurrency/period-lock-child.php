<?php
// Child process for period-lock-probe.php: ONE financial operation through the real services,
// in its own transaction (autocommit connection, as in production). Prints one JSON object.
// Usage: php period-lock-child.php <action> <arg1> [arg2] [--as=userId] [--lock-timeout=seconds]
//   payment <invoiceId> <date> | credit <invoiceId> <date> | issue <invoiceId> | cancel <invoiceId>
//   close <periodId> | reopen <periodId> | create <startsOn> <endsOn>
$root = dirname(__DIR__, 2);
require $root . '/app/autoload.php';
require $root . '/tests/lite/Assert.php';
require $root . '/tests/lite/Support/LocalApp.php';

use App\Core\Exceptions\ValidationException;

$opts = [];
$args = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)=(.*)$/', $arg, $m)) {
        $opts[$m[1]] = $m[2];
    } else {
        $args[] = $arg;
    }
}
$a = Tests\Lite\Support\LocalApp::boot();
$a->rollBack(); // no outer transaction, as in production
$a->locale('en');
$user = $a->admin;
if (isset($opts['as'])) {
    $user = (new App\Infrastructure\Repositories\UserRepository())->findById((int) $opts['as']);
    App\Infrastructure\Security\CurrentUser::set($user);
    App\Infrastructure\Repositories\OrganizationContext::set((int) $user['organization_id']);
}
if (isset($opts['lock-timeout'])) {
    $a->pdo->exec('SET SESSION innodb_lock_wait_timeout = ' . (int) $opts['lock-timeout']);
}
$c = $a->container();
$auth = $c->make(App\Infrastructure\Authorization\AuthorizationEngine::class);
$periods = new Modules\Finance\Services\FinancialPeriodService(new Modules\Finance\FinancialPeriodRepository(), $auth);
[$action, $x, $y] = array_pad($args, 3, null);
$t = microtime(true);
try {
    $result = match ($action) {
        'payment' => (new Modules\Finance\Services\PaymentService(new Modules\Finance\PaymentRepository(), new Modules\Finance\InvoiceRepository(), new Modules\Finance\CreditNoteRepository(), $auth, $periods))
            ->record($user, (int) $x, ['amount' => '10', 'payment_method' => 'cash', 'payment_date' => $y]),
        'credit' => (new Modules\Finance\Services\CreditNoteService(new Modules\Finance\CreditNoteRepository(), new Modules\Finance\CreditNoteNumberSeriesRepository(), new Modules\Finance\InvoiceRepository(), $auth, $periods))
            ->issue($user, (int) $x, ['amount' => '10', 'reason' => 'PERIOD-PROBE', 'issue_date' => $y]),
        'issue' => $c->make(Modules\Finance\Services\InvoiceService::class)->issue($user, (int) $x)['status'],
        'cancel' => $c->make(Modules\Finance\Services\InvoiceService::class)->cancel($user, (int) $x, 'PERIOD-PROBE')['status'],
        'close' => $periods->close($user, (int) $x)['status'],
        'reopen' => $periods->reopen($user, (int) $x)['status'],
        'create' => $periods->create($user, ['starts_on' => $x, 'ends_on' => $y])['id'],
    };
    $out = ['result' => 'OK', 'detail' => $result];
} catch (ValidationException $e) {
    $out = ['result' => 'REFUSED', 'detail' => $e->errors];
} catch (\Throwable $e) {
    $out = ['result' => 'ERROR', 'detail' => get_class($e) . ': ' . $e->getMessage()];
}
echo json_encode($out + ['waited' => round(microtime(true) - $t, 2)], JSON_UNESCAPED_UNICODE);
