<?php
// Stage 2 (docs/project-finance-design.md): real two-connection checks of the
// installment / conversion locks, which the single-process lite suite can't
// exercise. crm_local only (LocalApp refuses anything else).
//
// Each scenario commits a temporary contract (+ installment), then THIS process
// does the first action inside an open transaction (holding the row lock), a
// child process (installment-child.php) sends the competing request through the
// real router, and this process commits after 2 s.
//   doc      two documents from one installment         → child refused (422)
//   void     void while a document is being created      → child refused (422)
//   edit     edit while a document is being created      → child refused (422)
//   convert  the same proforma converted twice           → one invoice only
//   add      first installments in two currencies on a contract without one
//            → the second fails on the sequence key; one currency only
// Every temporary row (and the Contracts module row, if this script added it)
// is deleted in finally.
// Usage: php tests/concurrency/installment-lock-probe.php [doc|void|edit|convert|add ...]
$root = dirname(__DIR__, 2);
require $root . '/app/autoload.php';
require $root . '/tests/lite/Assert.php';
require $root . '/tests/lite/Support/LocalApp.php';

use Modules\Finance\CreditNoteRepository;
use Modules\Finance\FinanceDocumentLinkRepository;
use Modules\Finance\InstallmentRepository;
use Modules\Finance\InvoiceRepository;
use Modules\Finance\ProformaRepository;
use Modules\Finance\Services\ContractBillingService;
use Modules\Finance\Services\InstallmentService;
use Modules\Finance\Services\InvoiceService;
use Modules\Finance\Services\ProformaService;

$a = Tests\Lite\Support\LocalApp::boot();
$a->rollBack();
$a->locale('en');
$pdo = $a->pdo;
$c = $a->container();
$installments = new InstallmentService(
    new InstallmentRepository(), new FinanceDocumentLinkRepository(), new ProformaRepository(), new InvoiceRepository(), new CreditNoteRepository(),
    $c->make(ProformaService::class), $c->make(InvoiceService::class),
    $c->make(App\Infrastructure\Authorization\AuthorizationEngine::class), $c->make(App\Application\Services\TimelineService::class),
    $c->make(App\Infrastructure\Records\RecordTypeRegistry::class),
);
$billing = new ContractBillingService(
    $c->make(ProformaService::class), $c->make(InvoiceService::class), new FinanceDocumentLinkRepository(),
    $c->make(App\Application\Services\TimelineService::class), $c->make(App\Infrastructure\Records\RecordTypeRegistry::class),
    installments: $installments,
);
[$cur1, $cur2] = array_map('intval', $pdo->query('SELECT id FROM currencies WHERE is_active = 1 ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN));
$admin = (int) $a->admin['id'];
$doc = fn (int $cur) => ['currency_id' => (string) $cur, 'pricing_mode' => 'tax_exclusive', 'issue_date' => date('Y-m-d'), 'tax_profile_id' => '',
    'items' => [['description' => 'CONCURRENCY-PROBE line', 'quantity' => '1', 'unit_price' => '10', 'discount_type' => 'none', 'discount_value' => '0']]];

// Contracts must be installed + entitled (committed) for the child to see a contract; remember what we add.
$addedModule = false;
$moduleId = (int) $pdo->query("SELECT id FROM modules WHERE `key` = 'contracts'")->fetchColumn();
if ($moduleId === 0) {
    $pdo->exec("INSERT INTO modules (`key`, name, version, type, is_core, status, dependencies) VALUES ('contracts', 'Contracts', '0.1.0', 'addon', 0, 'active', '[]')");
    $moduleId = (int) $pdo->lastInsertId();
    $addedModule = true;
}
$addedEntitlement = false;
if ((int) $pdo->query("SELECT COUNT(*) FROM organization_modules WHERE organization_id = {$a->orgId} AND module_id = {$moduleId}")->fetchColumn() === 0) {
    $pdo->exec("INSERT INTO organization_modules (organization_id, module_id, enabled) VALUES ({$a->orgId}, {$moduleId}, 1)");
    $addedEntitlement = true;
}

$contracts = [];
$newContract = function (?int $currency) use ($pdo, $a, $admin, &$contracts): int {
    $pdo->prepare("INSERT INTO contracts (organization_id, title, owner_id, status, currency_id, created_by_user_id) VALUES (?, 'CONCURRENCY-PROBE', ?, 'active', ?, ?)")
        ->execute([$a->orgId, $admin, $currency, $admin]);

    return $contracts[] = (int) $pdo->lastInsertId();
};
$newInstallment = function (int $contractId, int $currency) use ($pdo, $a): int {
    $pdo->prepare("INSERT INTO finance_installments (organization_id, linked_type, linked_id, sequence, due_date, amount, currency_id) VALUES (?, 'contract', ?, 1, '2031-01-01', 10, ?)")
        ->execute([$a->orgId, $contractId, $currency]);

    return (int) $pdo->lastInsertId();
};
$run = function (callable $first, string $action, int $id, int $currency = 0) use ($pdo): string {
    $pdo->beginTransaction();
    $first();                                         // holds the lock, uncommitted
    $proc = proc_open([PHP_BINARY, __DIR__ . '/installment-child.php', $action, (string) $id, (string) $currency], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    usleep(2000000);
    $pdo->commit();
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    proc_close($proc);

    return trim($out);
};
$activeDocs = fn (int $i): int => (int) $pdo->query("SELECT
    (SELECT COUNT(*) FROM finance_document_links l JOIN invoices v ON v.id = l.document_id WHERE l.document_type = 'invoice' AND l.linked_type = 'installment' AND l.linked_id = {$i} AND v.status <> 'cancelled')
  + (SELECT COUNT(*) FROM finance_document_links l JOIN proformas p ON p.id = l.document_id WHERE l.document_type = 'proforma' AND l.linked_type = 'installment' AND l.linked_id = {$i} AND p.converted_invoice_id IS NULL)")->fetchColumn();

$scenarios = array_slice($argv, 1) ?: ['doc', 'void', 'edit', 'convert', 'add'];
try {
    foreach ($scenarios as $s) {
        $k = $newContract($s === 'add' ? null : $cur1);
        switch ($s) {
            case 'doc':
            case 'void':
            case 'edit':
                $i = $newInstallment($k, $cur1);
                $out = $run(fn () => $billing->createLinked($a->admin, 'invoice', $doc($cur1), 'installment', $i), $s, $i, $cur1);
                $row = $pdo->query("SELECT voided_at, amount FROM finance_installments WHERE id = {$i}")->fetch(PDO::FETCH_ASSOC);
                printf("%-8s child=%s | active documents=%d voided=%s amount=%s\n", $s, $out, $activeDocs($i), $row['voided_at'] === null ? 'no' : 'YES', rtrim(rtrim($row['amount'], '0'), '.'));
                break;
            case 'convert':
                $i = $newInstallment($k, $cur1);
                $pf = (int) $billing->createLinked($a->admin, 'proforma', $doc($cur1), 'installment', $i)['id']; // committed
                $out = $run(fn () => $c->make(InvoiceService::class)->createFromProforma($a->admin, $pf), 'convert', $pf);
                printf("%-8s child=%s | invoices from the proforma=%d, active documents=%d\n", $s, $out, (int) $pdo->query("SELECT COUNT(*) FROM invoices WHERE proforma_id = {$pf}")->fetchColumn(), $activeDocs($i));
                break;
            case 'add':
                $out = $run(fn () => $installments->create($a->admin, $k, ['due_date' => '2031-01-01', 'amount' => '10', 'currency_id' => (string) $cur1]), 'add', $k, $cur2);
                $cur = $pdo->query("SELECT GROUP_CONCAT(DISTINCT currency_id) FROM finance_installments WHERE linked_type = 'contract' AND linked_id = {$k}")->fetchColumn();
                printf("%-8s child=%s | installments=%d currencies=%s\n", $s, $out, (int) $pdo->query("SELECT COUNT(*) FROM finance_installments WHERE linked_type = 'contract' AND linked_id = {$k}")->fetchColumn(), $cur);
                break;
        }
    }
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    foreach ($contracts as $k) {
        $docs = $pdo->query("SELECT document_type, document_id FROM finance_document_links WHERE linked_type = 'contract' AND linked_id = {$k}")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($docs as $d) {
            $pdo->exec("DELETE FROM finance_document_links WHERE document_type = '{$d['document_type']}' AND document_id = {$d['document_id']}");
        }
        foreach ($docs as $d) {
            if ($d['document_type'] === 'invoice') {
                $pdo->exec("DELETE FROM invoices WHERE id = {$d['document_id']}");
            }
        }
        foreach ($docs as $d) {
            if ($d['document_type'] === 'proforma') {
                $pdo->exec("DELETE FROM invoices WHERE proforma_id = {$d['document_id']}");
                $pdo->exec("DELETE FROM proformas WHERE id = {$d['document_id']}");
            }
        }
        $pdo->exec("DELETE FROM finance_installments WHERE linked_type = 'contract' AND linked_id = {$k}");
        $pdo->exec("DELETE FROM timeline_events WHERE subject_type = 'contract' AND subject_id = {$k}");
        $pdo->exec("DELETE FROM contracts WHERE id = {$k} AND title = 'CONCURRENCY-PROBE'");
    }
    if ($addedEntitlement) {
        $pdo->exec("DELETE FROM organization_modules WHERE organization_id = {$a->orgId} AND module_id = {$moduleId}");
    }
    if ($addedModule) {
        $pdo->exec("DELETE FROM modules WHERE id = {$moduleId} AND `key` = 'contracts'");
    }
}
echo 'leftover rows: ', (int) $pdo->query("SELECT (SELECT COUNT(*) FROM contracts WHERE title = 'CONCURRENCY-PROBE') + (SELECT COUNT(*) FROM invoice_items WHERE description = 'CONCURRENCY-PROBE line') + (SELECT COUNT(*) FROM proforma_items WHERE description = 'CONCURRENCY-PROBE line')")->fetchColumn(),
    ', contracts module ', $addedModule ? 'removed again' : 'was already installed', "\n";
