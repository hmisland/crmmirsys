<?php

declare(strict_types=1);

/**
 * Stage 1 (docs/project-finance-design.md): credit-note effect in the
 * contract Billing summary.
 *
 * Per ISSUED invoice, in its own currency:
 *   Credited   = Σ credit notes
 *   Net billed = Grand − Credited
 *   Receivable = max(0, Grand − Credited − Paid)       (summary 'balance')
 *   Refund due = max(0, Paid − (Grand − Credited))
 * Drafts never count; cancelled invoices are out of every main figure and
 * a payment recorded on one is reported separately (paid_on_cancelled).
 * Credited / Net billed are shown only with credit_notes.view.
 *
 * Fixtures write status / paid_total / credit_notes rows directly:
 * issue(), payments and credit notes open their own DB transaction, which
 * can't nest inside this suite's rollback transaction. The summary reads
 * only these stored values. crm_local only, one transaction, rolled back.
 */

require_once __DIR__ . '/Support/LocalApp.php';

use Modules\Finance\FinanceDocumentLinkRepository;
use Modules\Finance\Services\ContractBillingService;
use Modules\Finance\Services\InvoiceService;
use Modules\Finance\Services\ProformaService;
use Tests\Lite\Support\LocalApp;

$h = new \stdClass();
$h->app = null;
$h->reason = '';

$boot = function () use ($h): LocalApp {
    if ($h->app !== null) {
        return $h->app;
    }
    if ($h->reason !== '') {
        throw new LiteSkip($h->reason);
    }
    try {
        $a = LocalApp::boot();
    } catch (\Throwable $e) {
        $h->reason = 'local database unavailable: ' . $e->getMessage();
        throw new LiteSkip($h->reason);
    }
    if ($a->member === null || (int) $a->pdo->query('SELECT COUNT(*) FROM currencies WHERE is_active = 1')->fetchColumn() < 2) {
        $a->rollBack();
        $h->reason = 'needs a second active user and two active currencies';
        throw new LiteSkip($h->reason);
    }
    $a->activateContracts();
    $finance = $a->pdo->query("SELECT id FROM modules WHERE `key` = 'finance'")->fetchColumn();
    if ($finance === false) {
        $a->rollBack();
        $h->reason = 'Finance not installed in crm_local';
        throw new LiteSkip($h->reason);
    }
    $a->pdo->prepare("UPDATE modules SET status = 'active' WHERE id = ?")->execute([(int) $finance]);
    $a->pdo->prepare('DELETE FROM organization_modules WHERE organization_id = ? AND module_id = ?')->execute([$a->orgId, (int) $finance]);
    $a->pdo->prepare('INSERT INTO organization_modules (organization_id, module_id, enabled) VALUES (?, ?, 1)')->execute([$a->orgId, (int) $finance]);
    $a->as($a->admin);
    $h->currencies = array_map('intval', $a->pdo->query('SELECT id FROM currencies WHERE is_active = 1 ORDER BY id LIMIT 2')->fetchAll(\PDO::FETCH_COLUMN));
    $h->app = $a;

    return $a;
};

$billing = fn (LocalApp $a): ContractBillingService => new ContractBillingService(
    $a->container()->make(ProformaService::class),
    $a->container()->make(InvoiceService::class),
    new FinanceDocumentLinkRepository(),
    $a->container()->make(\App\Application\Services\TimelineService::class),
    $a->container()->make(\App\Infrastructure\Records\RecordTypeRegistry::class),
);
$engine = fn (LocalApp $a) => $a->container()->make(\App\Infrastructure\Authorization\AuthorizationEngine::class);
$canView = fn (LocalApp $a, array $user) => fn (string $key): bool => $engine($a)->can($user, $key);

/** A fresh contract of the admin. */
$contract = function (LocalApp $a): int {
    $a->as($a->admin);

    return (int) $a->container()->make(\Modules\Contracts\Services\ContractService::class)->create($a->admin, ['title' => 'LiteTest CE contract'])['id'];
};

/**
 * An invoice linked to $contractId, created by $user through the real
 * services (one line: $amount, no tax profile → grand_total = $amount),
 * then given the stored status / paid_total of the scenario.
 */
$invoice = function (LocalApp $a, int $contractId, string $amount, string $status = 'issued', string $paid = '0', ?int $currency = null, ?array $user = null) use ($h, $billing): int {
    $user ??= $a->admin;
    $a->as($user);
    $inv = $billing($a)->createLinked($user, 'invoice', [
        'currency_id' => (string) ($currency ?? $h->currencies[0]),
        'pricing_mode' => 'tax_exclusive',
        'issue_date' => date('Y-m-d'),
        'tax_profile_id' => '',
        'items' => [['description' => 'LiteTest CE line', 'quantity' => '1', 'unit_price' => $amount, 'discount_type' => 'none', 'discount_value' => '0']],
    ], 'contract', $contractId);
    $a->as($a->admin);
    // Force a known total whatever the organization's default tax profile is.
    $a->pdo->prepare('UPDATE invoices SET subtotal = ?, tax_total = 0, grand_total = ?, status = ?, paid_total = ? WHERE id = ?')
        ->execute([$amount, $amount, $status, $paid, (int) $inv['id']]);

    return (int) $inv['id'];
};
$credit = function (LocalApp $a, int $invoiceId, string $amount, ?int $orgId = null): void {
    $a->pdo->prepare("INSERT INTO credit_notes (organization_id, invoice_id, credit_note_number, issue_date, reason, amount, issued_by_user_id) VALUES (?, ?, ?, CURDATE(), 'LiteTest', ?, ?)")
        ->execute([$orgId ?? $a->orgId, $invoiceId, 'LT-CN-' . bin2hex(random_bytes(4)), $amount, (int) $a->admin['id']]);
};
/** The summary entry for one currency (admin, with credit_notes.view unless $user given). */
$summaryFor = function (LocalApp $a, int $contractId, ?int $currency = null, ?array $user = null) use ($h, $billing, $canView): ?array {
    $user ??= $a->admin;
    $data = $billing($a)->panelFor($user, $contractId, $canView($a, $user));
    foreach ($data['summary'] as $s) {
        if ($s['currency_id'] === ($currency ?? $h->currencies[0])) {
            return $s;
        }
    }

    return null;
};
$n = fn (string $v): string => bcadd($v, '0', 18); // normalise to the summary's 18-decimal scale
$assertFigures = function (?array $s, array $expected, string $label) use ($n): void {
    LiteAssert::true($s !== null, "{$label}: summary entry exists");
    foreach ($expected as $key => $value) {
        if ($value === null) {
            LiteAssert::same(null, $s[$key], "{$label}: {$key} must be null");
            continue;
        }
        LiteAssert::true(is_string($s[$key]) && bccomp($s[$key], $n($value), 18) === 0, "{$label}: {$key} expected {$value}, got " . var_export($s[$key], true));
    }
};

$tests = [];

$tests['1. partial payment, no credit: receivable = grand − paid, refund 0'] = function () use ($boot, $contract, $invoice, $summaryFor, $assertFigures) {
    $a = $boot();
    $c = $contract($a);
    $invoice($a, $c, '1000', 'issued', '300');
    $assertFigures($summaryFor($a, $c), ['billed' => '1000', 'credited' => '0', 'net_billed' => '1000', 'paid' => '300', 'balance' => '700', 'refund_due' => '0'], 'partial payment');
};

$tests['2. full payment: receivable 0, refund 0'] = function () use ($boot, $contract, $invoice, $summaryFor, $assertFigures) {
    $a = $boot();
    $c = $contract($a);
    $invoice($a, $c, '1000', 'issued', '1000');
    $assertFigures($summaryFor($a, $c), ['billed' => '1000', 'paid' => '1000', 'balance' => '0', 'refund_due' => '0'], 'full payment');
};

$tests['3. partial credit note: credited, net billed, receivable reduced'] = function () use ($boot, $contract, $invoice, $credit, $summaryFor, $assertFigures) {
    $a = $boot();
    $c = $contract($a);
    $i = $invoice($a, $c, '1000');
    $credit($a, $i, '150');
    $assertFigures($summaryFor($a, $c), ['billed' => '1000', 'credited' => '150', 'net_billed' => '850', 'paid' => '0', 'balance' => '850', 'refund_due' => '0'], 'partial credit');
};

$tests['4. full credit note: net billed 0, receivable 0'] = function () use ($boot, $contract, $invoice, $credit, $summaryFor, $assertFigures) {
    $a = $boot();
    $c = $contract($a);
    $i = $invoice($a, $c, '1000');
    $credit($a, $i, '600');
    $credit($a, $i, '400');
    $assertFigures($summaryFor($a, $c), ['billed' => '1000', 'credited' => '1000', 'net_billed' => '0', 'balance' => '0', 'refund_due' => '0'], 'full credit');
};

$tests['5. partial payment then credit: receivable = grand − credited − paid'] = function () use ($boot, $contract, $invoice, $credit, $summaryFor, $assertFigures) {
    $a = $boot();
    $c = $contract($a);
    $i = $invoice($a, $c, '1000', 'issued', '200');
    $credit($a, $i, '300');
    $assertFigures($summaryFor($a, $c), ['credited' => '300', 'net_billed' => '700', 'paid' => '200', 'balance' => '500', 'refund_due' => '0'], 'payment + credit');
};

$tests['6. full payment then credit: receivable 0, refund due = credit (never negative balance)'] = function () use ($boot, $contract, $invoice, $credit, $summaryFor, $assertFigures) {
    $a = $boot();
    $c = $contract($a);
    $i = $invoice($a, $c, '1000', 'issued', '1000');
    $credit($a, $i, '250');
    $assertFigures($summaryFor($a, $c), ['credited' => '250', 'net_billed' => '750', 'paid' => '1000', 'balance' => '0', 'refund_due' => '250'], 'paid then credited');
};

$tests['7. receivable and refund are clamped PER INVOICE (one invoice over-paid does not reduce another\'s receivable)'] = function () use ($boot, $contract, $invoice, $credit, $summaryFor, $assertFigures) {
    $a = $boot();
    $c = $contract($a);
    $over = $invoice($a, $c, '1000', 'issued', '1000');
    $credit($a, $over, '400');                  // refund due 400
    $invoice($a, $c, '500', 'issued', '0');     // receivable 500
    $assertFigures($summaryFor($a, $c), ['billed' => '1500', 'credited' => '400', 'net_billed' => '1100', 'paid' => '1000', 'balance' => '500', 'refund_due' => '400'], 'per-invoice clamp');
};

$tests['8. cancelled invoice WITH payment: out of billed/credited/paid/receivable; payment only in the separate warning'] = function () use ($boot, $contract, $invoice, $credit, $summaryFor, $assertFigures) {
    $a = $boot();
    $c = $contract($a);
    $invoice($a, $c, '1000', 'issued', '100');
    $cancelled = $invoice($a, $c, '800', 'cancelled', '350');
    $credit($a, $cancelled, '50'); // a credit issued before it was cancelled
    $assertFigures($summaryFor($a, $c), ['billed' => '1000', 'credited' => '0', 'paid' => '100', 'balance' => '900', 'paid_on_cancelled' => '350'], 'cancelled with payment');
    // Rendered: the warning says it is a payment on a cancelled invoice and not valid revenue.
    $a->locale('en');
    $body = $a->request('GET', "/contracts/{$c}")['body'];
    $en = require dirname(__DIR__, 2) . '/lang/en.php';
    LiteAssert::contains(htmlspecialchars($en['finance.paid_on_cancelled'], ENT_QUOTES), $body);
    LiteAssert::contains(htmlspecialchars($en['finance.paid_on_cancelled_hint'], ENT_QUOTES), $body);
};

$tests['9. cancelled invoice WITHOUT payment: excluded entirely, no warning'] = function () use ($boot, $contract, $invoice, $summaryFor) {
    $a = $boot();
    $c = $contract($a);
    $invoice($a, $c, '800', 'cancelled', '0');
    LiteAssert::same(null, $summaryFor($a, $c), 'no summary entry at all');
    $en = require dirname(__DIR__, 2) . '/lang/en.php';
    $a->locale('en');
    LiteAssert::notContains(htmlspecialchars($en['finance.paid_on_cancelled'], ENT_QUOTES), $a->request('GET', "/contracts/{$c}")['body']);
};

$tests['10. drafts are never counted, even with a stray credit row'] = function () use ($boot, $contract, $invoice, $credit, $summaryFor, $assertFigures) {
    $a = $boot();
    $c = $contract($a);
    $draft = $invoice($a, $c, '5000', 'draft', '0');
    $credit($a, $draft, '100');
    LiteAssert::same(null, $summaryFor($a, $c), 'draft only → nothing counted');
    $invoice($a, $c, '1000');
    $assertFigures($summaryFor($a, $c), ['billed' => '1000', 'credited' => '0', 'balance' => '1000'], 'draft ignored next to an issued one');
};

$tests['11. multi-currency: each currency on its own, credits in the invoice\'s currency, no cross-currency sum'] = function () use ($boot, $h, $contract, $invoice, $credit, $summaryFor, $assertFigures, $billing, $canView) {
    $a = $boot();
    $c = $contract($a);
    [$cur1, $cur2] = $h->currencies;
    $i1 = $invoice($a, $c, '1000', 'issued', '100', $cur1);
    $i2 = $invoice($a, $c, '40', 'issued', '10', $cur2);
    $credit($a, $i1, '200');
    $credit($a, $i2, '5');
    $assertFigures($summaryFor($a, $c, $cur1), ['billed' => '1000', 'credited' => '200', 'paid' => '100', 'balance' => '700'], 'currency 1');
    $assertFigures($summaryFor($a, $c, $cur2), ['billed' => '40', 'credited' => '5', 'paid' => '10', 'balance' => '25'], 'currency 2');
    LiteAssert::same(2, count($billing($a)->panelFor($a->admin, $c, $canView($a, $a->admin))['summary']), 'exactly two currency entries');
};

$tests['12. credit_notes.view: admin sees Credited / Net billed; a user without it gets null there, yet a correct receivable'] = function () use ($boot, $contract, $invoice, $credit, $summaryFor, $assertFigures, $engine) {
    $a = $boot();
    $c = $contract($a);
    // A member-owned invoice (member: invoices.view own, no credit_notes.view in crm_local).
    $mine = $invoice($a, $c, '1000', 'issued', '100', null, $a->member);
    $credit($a, $mine, '300');
    LiteAssert::false($engine($a)->can($a->member, 'credit_notes.view'), 'precondition: member lacks credit_notes.view');
    $assertFigures($summaryFor($a, $c, null, $a->member), ['billed' => '1000', 'credited' => null, 'net_billed' => null, 'paid' => '100', 'balance' => '600', 'refund_due' => '0'], 'member');
    $assertFigures($summaryFor($a, $c), ['credited' => '300', 'net_billed' => '700', 'balance' => '600'], 'admin');
};

$tests['13. no credit-note detail is rendered for a user without credit_notes.view'] = function () use ($boot, $contract, $invoice, $credit) {
    $a = $boot();
    $c = $contract($a);
    $mine = $invoice($a, $c, '1000', 'issued', '0', null, $a->member);
    $credit($a, $mine, '123.45');
    $a->locale('en');
    $en = require dirname(__DIR__, 2) . '/lang/en.php';
    $panel = function (string $body): string {
        $start = strpos($body, 'data-related-panel="finance.contract"');

        return $start === false ? '' : substr($body, $start, strpos($body, '</section>', $start) - $start);
    };
    $a->as($a->member);
    $memberPanel = $panel($a->request('GET', "/contracts/{$c}")['body']);
    $a->as($a->admin);
    LiteAssert::true($memberPanel !== '', 'member sees the panel');
    LiteAssert::notContains($en['finance.credited'], $memberPanel, 'no Credited label');
    LiteAssert::notContains($en['finance.net_billed'], $memberPanel, 'no Net billed label');
    LiteAssert::notContains('123.45', $memberPanel, 'no credit amount');
    LiteAssert::contains('876.55', $memberPanel, 'receivable accounts for the credit');
    $adminPanel = $panel($a->request('GET', "/contracts/{$c}")['body']);
    LiteAssert::contains($en['finance.credited'], $adminPanel);
    LiteAssert::contains('123.45', $adminPanel);
};

$tests['14. scope: invoices the user may not view contribute nothing (amounts, credits, payments, cancelled warning)'] = function () use ($boot, $contract, $invoice, $credit, $summaryFor, $assertFigures) {
    $a = $boot();
    $c = $contract($a);
    $adminInv = $invoice($a, $c, '9000', 'issued', '500');
    $credit($a, $adminInv, '700');
    $invoice($a, $c, '777', 'cancelled', '77');               // admin-owned cancelled with payment
    $invoice($a, $c, '1000', 'issued', '0', null, $a->member); // the member's own
    $assertFigures($summaryFor($a, $c, null, $a->member), ['billed' => '1000', 'paid' => '0', 'balance' => '1000', 'refund_due' => '0', 'paid_on_cancelled' => '0'], 'member (own scope)');
    $assertFigures($summaryFor($a, $c), ['billed' => '10000', 'credited' => '700', 'paid' => '500', 'balance' => '8800', 'paid_on_cancelled' => '77'], 'admin (organization scope)');
};

$tests['15. organization isolation: another organization\'s credit note / invoice ids have no effect'] = function () use ($boot, $h, $contract, $invoice, $credit, $summaryFor, $assertFigures) {
    $a = $boot();
    if ($a->otherOrgId === null) {
        throw new LiteSkip('only one organization');
    }
    $c = $contract($a);
    $mine = $invoice($a, $c, '1000');
    // A credit-note row of the OTHER organization pointing at MY invoice id.
    $credit($a, $mine, '999', $a->otherOrgId);
    // Another organization's issued invoice, and a link of MY organization pointing at its id.
    $owner = $a->pdo->query("SELECT id FROM users WHERE organization_id = {$a->otherOrgId} ORDER BY id LIMIT 1")->fetchColumn();
    $owner = $owner === false ? (int) $a->admin['id'] : (int) $owner;
    $a->pdo->prepare("INSERT INTO invoices (organization_id, owner_id, issue_date, currency_id, pricing_mode, grand_total, paid_total, status) VALUES (?, ?, CURDATE(), ?, 'tax_exclusive', 5555, 55, 'issued')")
        ->execute([$a->otherOrgId, $owner, $h->currencies[0]]);
    $foreignInv = (int) $a->pdo->lastInsertId();
    $a->pdo->prepare("INSERT INTO finance_document_links (organization_id, document_type, document_id, linked_type, linked_id, linked_label) VALUES (?, 'invoice', ?, 'contract', ?, 'x')")->execute([$a->orgId, $foreignInv, $c]);
    $assertFigures($summaryFor($a, $c), ['billed' => '1000', 'credited' => '0', 'net_billed' => '1000', 'paid' => '0', 'balance' => '1000'], 'isolated');
};

$tests['16. all five languages: panel renders with the new rows, the warning and the corrected hint, no raw key'] = function () use ($boot, $contract, $invoice, $credit) {
    $a = $boot();
    $c = $contract($a);
    $i = $invoice($a, $c, '1000', 'issued', '1000');
    $credit($a, $i, '100');
    $invoice($a, $c, '50', 'cancelled', '20');
    $problems = [];
    foreach (['en', 'fa', 'ar', 'tr', 'fr'] as $lang) {
        $strings = require dirname(__DIR__, 2) . "/lang/{$lang}.php";
        $a->locale($lang);
        $r = $a->request('GET', "/contracts/{$c}");
        $text = html_entity_decode(strip_tags((string) preg_replace('#<(script|style)\b.*?</\1>#s', '', $r['body'])));
        preg_match_all('/\bfinance\.[a-z_]+(?:\.[a-z_]+)*\b/', $text, $m);
        if ($r['status'] !== 200 || $m[0] !== []) {
            $problems[] = "{$lang}: status {$r['status']} raw " . implode(',', $m[0]);
        }
        foreach (['finance.credited', 'finance.net_billed', 'finance.refund_due', 'finance.paid_on_cancelled', 'finance.paid_on_cancelled_hint', 'finance.billing_hint'] as $key) {
            if (!str_contains($text, $strings[$key])) {
                $problems[] = "{$lang}: {$key} not shown";
            }
        }
    }
    $a->locale('en');
    LiteAssert::same([], $problems, implode(' | ', $problems));
};

$tests['rollback leaves no test data behind'] = function () use ($boot) {
    $a = $boot();
    $a->rollBack();
    LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM credit_notes WHERE reason = 'LiteTest'")->fetchColumn());
    LiteAssert::same(0, (int) $a->pdo->query('SELECT COUNT(*) FROM finance_document_links')->fetchColumn());
};

return $tests;
