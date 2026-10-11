<?php

declare(strict_types=1);

/**
 * Finance validation & reconciliation — approved remediation P1, P2, P3.
 *
 * P1 (audit H1, decision D1 = A): the period report is per currency (never
 *    one total across currencies) and covers the invoices ISSUED in the
 *    period with THEIR credit notes, whatever the credit-note date.
 * P2 (audit M1, decision D2): "outstanding" is grand − credited − paid on
 *    the invoice page, the contact panel and ClientRecords (page, invoices tab,
 *    API `outstanding`); the API's existing `remaining` is unchanged.
 * P3 (audit M2, decision D3): invoice / proforma lines, payments and credit
 *    notes accept "1,000", refuse malformed or negative values, discounts above
 *    100 % and fixed discounts above the line amount — a validation answer,
 *    never an uncaught ValueError (500). Valid amounts are unchanged.
 *
 * crm_local only, one transaction, rolled back.
 */

require_once __DIR__ . '/Support/LocalApp.php';

use App\Core\Exceptions\ValidationException;
use Modules\Finance\Services\CreditNoteService;
use Modules\Finance\Services\InvoiceService;
use Modules\Finance\Services\PaymentService;
use Modules\Finance\Services\ProformaService;
use Modules\Finance\Services\ReportService;
use Tests\Lite\Support\LocalApp;

$h = new \stdClass();
$h->app = null;
$boot = function () use ($h): LocalApp {
    if ($h->app !== null) {
        return $h->app;
    }
    $a = LocalApp::boot();
    foreach (['finance', 'client_records'] as $key) {
        $id = (int) $a->pdo->query("SELECT id FROM modules WHERE `key` = '{$key}'")->fetchColumn();
        if ($id === 0) {
            if ($key === 'finance') {
                $a->rollBack();
                throw new LiteSkip('Finance not installed in crm_local');
            }
            continue;
        }
        $a->pdo->prepare("UPDATE modules SET status = 'active' WHERE id = ?")->execute([$id]);
        $a->pdo->prepare('DELETE FROM organization_modules WHERE organization_id = ? AND module_id = ?')->execute([$a->orgId, $id]);
        $a->pdo->prepare('INSERT INTO organization_modules (organization_id, module_id, enabled) VALUES (?, ?, 1)')->execute([$a->orgId, $id]);
    }
    $a->pdo->prepare("DELETE FROM financial_periods WHERE organization_id = ? AND status = 'closed'")->execute([$a->orgId]);
    $a->as($a->admin);
    $a->locale('en');
    $h->irr = (int) $a->pdo->query("SELECT id FROM currencies WHERE code = 'IRR'")->fetchColumn();
    $h->usd = (int) $a->pdo->query("SELECT id FROM currencies WHERE code = 'USD'")->fetchColumn();
    if ($h->irr === 0 || $h->usd === 0) {
        $a->rollBack();
        throw new LiteSkip('needs IRR and USD');
    }
    $h->app = $a;

    return $a;
};
$c = fn (LocalApp $a) => $a->container();
$auth = fn (LocalApp $a) => $c($a)->make(\App\Infrastructure\Authorization\AuthorizationEngine::class);
$periods = fn (LocalApp $a) => new \Modules\Finance\Services\FinancialPeriodService(new \Modules\Finance\FinancialPeriodRepository(), $auth($a));
/** Built with the same arguments FinanceModule passes (the routes cover the real wiring). */
$report = fn (LocalApp $a): ReportService => new ReportService(new \Modules\Finance\InvoiceRepository(), new \Modules\Finance\CreditNoteRepository(), $auth($a));
$payments = fn (LocalApp $a): PaymentService => new PaymentService(new \Modules\Finance\PaymentRepository(), new \Modules\Finance\InvoiceRepository(), new \Modules\Finance\CreditNoteRepository(), $auth($a), $periods($a));
$credits = fn (LocalApp $a): CreditNoteService => new CreditNoteService(new \Modules\Finance\CreditNoteRepository(), new \Modules\Finance\CreditNoteNumberSeriesRepository(), new \Modules\Finance\InvoiceRepository(), $auth($a), $periods($a));
$contact = function (LocalApp $a): int {
    return (int) $a->container()->make(\App\Application\Services\ContactService::class)->create($a->admin, ['first_name' => 'LiteTest FR', 'last_name' => bin2hex(random_bytes(3))])['id'];
};
/** A stored issued invoice (no service side effects), optionally with a payment row and a credit note. */
$invoice = function (LocalApp $a, int $currency, string $grand, string $date, string $paid = '0', ?string $credit = null, ?string $creditDate = null, string $status = 'issued', ?int $contactId = null, string $tax = '0', ?int $org = null) {
    $org ??= $a->orgId;
    $a->pdo->prepare("INSERT INTO invoices (organization_id, contact_id, owner_id, issue_date, currency_id, subtotal, tax_total, grand_total, paid_total, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'LiteTest FR')")
        ->execute([$org, $contactId, (int) $a->admin['id'], $date, $currency, bcsub($grand, $tax, 18), $tax, $grand, $paid, $status]);
    $id = (int) $a->pdo->lastInsertId();
    if (bccomp($paid, '0', 18) > 0) {
        $a->pdo->prepare("INSERT INTO payments (organization_id, invoice_id, amount, payment_method, payment_date, recorded_by_user_id) VALUES (?, ?, ?, 'cash', ?, ?)")->execute([$org, $id, $paid, $date, (int) $a->admin['id']]);
    }
    if ($credit !== null) {
        $a->pdo->prepare("INSERT INTO credit_notes (organization_id, invoice_id, credit_note_number, issue_date, reason, amount, issued_by_user_id) VALUES (?, ?, ?, ?, 'LiteTest FR', ?, ?)")
            ->execute([$org, $id, 'LTFR' . strtoupper(bin2hex(random_bytes(3))), $creditDate ?? $date, $credit, (int) $a->admin['id']]);
    }

    return $id;
};
$is = fn (string $expected, string $actual, string $msg = '') => LiteAssert::same(0, bccomp($expected, $actual, 18), "{$msg}: expected {$expected}, got {$actual}");
$text = fn (string $html): string => preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html)));
$userWith = function (LocalApp $a, array $keys): array {
    $a->pdo->prepare('INSERT INTO roles (organization_id, `key`, name, is_system) VALUES (?, ?, ?, 0)')->execute([$a->orgId, 'litetest_fr_' . bin2hex(random_bytes(4)), 'LiteTest FR']);
    $role = (int) $a->pdo->lastInsertId();
    foreach ($keys as $key) {
        $a->pdo->prepare("INSERT INTO role_permissions (role_id, permission_key, scope) VALUES (?, ?, 'organization')")->execute([$role, $key]);
    }

    return array_merge($a->member, ['role_id' => $role]);
};
$line = fn (array $over = []): array => $over + ['description' => 'LiteTest FR line', 'quantity' => '2', 'unit_price' => '100', 'discount_type' => 'none', 'discount_value' => '0'];
$doc = fn (LocalApp $a, string $class, array $lines) => $c($a)->make($class)->create($a->admin, ['currency_id' => (string) $h->irr, 'pricing_mode' => 'tax_exclusive', 'issue_date' => date('Y-m-d'), 'items' => $lines]);
$refused = function (callable $fn, string $field, string $needle = ''): void {
    try {
        $fn();
    } catch (ValidationException $e) {
        LiteAssert::true(isset($e->errors[$field]), "expected {$field}, got " . json_encode($e->errors, JSON_UNESCAPED_UNICODE));
        if ($needle !== '') {
            LiteAssert::contains($needle, json_encode($e->errors[$field], JSON_UNESCAPED_UNICODE));
        }

        return;
    }
    throw new LiteAssertionFailed("accepted (expected a validation error on {$field})");
};

$tests = [];

// ---- P1: period report --------------------------------------------------------------------

$tests['P1-1 (A1) mixed currencies: one row per currency, never one total; the page shows both, no combined figure'] = function () use ($boot, $h, $report, $invoice, $is, $text) {
    $a = $boot();
    $invoice($a, $h->irr, '1000000', '1990-05-01', '0', null, null, 'issued', null, '90000');
    $invoice($a, $h->usd, '10', '1990-05-02', '4');
    $r = $report($a)->summary($a->admin, '1990-01-01', '1990-12-31');
    LiteAssert::same([$h->irr, $h->usd], array_keys($r['currencies']));
    LiteAssert::false(isset($r['gross_revenue']) || isset($r['net_revenue']) || isset($r['outstanding']), 'no cross-currency money total');
    $is('1000000', $r['currencies'][$h->irr]['gross_revenue'], 'IRR gross');
    $is('90000', $r['currencies'][$h->irr]['total_tax'], 'IRR tax');
    $is('10', $r['currencies'][$h->usd]['gross_revenue'], 'USD gross');
    $is('6', $r['currencies'][$h->usd]['outstanding'], 'USD outstanding 10 − 4');
    LiteAssert::same(2, $r['invoice_count'], 'a count may be added up');
    $page = $text($a->request('GET', '/finance/reports', ['start_date' => '1990-01-01', 'end_date' => '1990-12-31'])['body']);
    LiteAssert::contains('IRR 1 1,000,000', $page);
    LiteAssert::contains('USD 1 10', $page);
    LiteAssert::notContains('1,000,010', $page, 'no mixed sum anywhere');
};

$tests['P1-2 (A2) period basis: invoices issued in the period with ALL their credit notes; a later invoice\'s credit note never lands in an earlier period'] = function () use ($boot, $h, $report, $invoice, $is) {
    $a = $boot();
    $invoice($a, $h->irr, '5000', '1991-12-15', '0', '5000', '1992-01-10');     // credit dated after the period
    $invoice($a, $h->irr, '800', '1991-06-01', '200', '300', '1991-06-02');
    $r91 = $report($a)->summary($a->admin, '1991-01-01', '1991-12-31')['currencies'][$h->irr];
    $is('5800', $r91['gross_revenue'], 'gross');
    $is('5300', $r91['total_credited'], 'credit notes of these invoices, whatever their date');
    $is('500', $r91['net_revenue'], 'net');
    $is('300', $r91['outstanding'], 'outstanding = 500 − 200');
    $r92 = $report($a)->summary($a->admin, '1992-01-01', '1992-12-31');
    LiteAssert::same([], $r92['currencies'], '1992: no invoice issued → no row (formerly net −5,000)');
    LiteAssert::same(0, $r92['invoice_count']);
};

$tests['P1-3 exclusions, unchanged valid figures, permission, tenant isolation, label of the basis'] = function () use ($boot, $h, $report, $invoice, $is, $userWith, $text) {
    $a = $boot();
    $invoice($a, $h->irr, '700', '1993-02-01', '100');
    $invoice($a, $h->irr, '9999', '1993-02-02', '0', null, null, 'draft');
    $invoice($a, $h->irr, '8888', '1993-02-03', '0', null, null, 'cancelled');
    if ($a->otherOrgId !== null) {
        $invoice($a, $h->irr, '7777', '1993-02-04', '0', '50', null, 'issued', null, '0', $a->otherOrgId);
    }
    $row = $report($a)->summary($a->admin, '1993-01-01', '1993-12-31')['currencies'][$h->irr];
    LiteAssert::same(1, $row['invoice_count'], 'draft, cancelled and other organizations excluded');
    $is('700', $row['gross_revenue']);
    $is('0', $row['total_credited']);
    $is('100', $row['total_paid']);
    $is('600', $row['outstanding']);
    $noReports = $userWith($a, ['invoices.view']);
    $a->as($noReports);
    try {
        LiteAssert::same('forbidden', $a->outcome('GET', '/finance/reports'));
    } finally {
        $a->as($a->admin);
    }
    try {
        foreach (['en', 'fa', 'ar', 'tr', 'fr'] as $locale) {
            $a->locale($locale);
            $lang = require dirname(__DIR__, 2) . "/lang/{$locale}.php";
            $body = $a->request('GET', '/finance/reports', ['start_date' => '1993-01-01', 'end_date' => '1993-12-31'])['body'];
            LiteAssert::contains(htmlspecialchars($lang['reports.period_basis'], ENT_QUOTES), $body, "{$locale}: basis label");
            LiteAssert::notContains('reports.', $text($body), "{$locale}: raw key");
        }
    } finally {
        $a->locale('en');
    }
};

// ---- P2: outstanding consistency -----------------------------------------------------------

$tests['P2-1 (A3) same invoice, same outstanding: invoice page 600, contact panel 600, client record page and invoices tab 600, API outstanding 600 (remaining kept at 900)'] = function () use ($boot, $h, $invoice, $contact, $text) {
    $a = $boot();
    $cid = $contact($a);
    $id = $invoice($a, $h->irr, '1000', date('Y-m-d'), '100', '300', null, 'issued', $cid);
    $invoice($a, $h->irr, '5000', date('Y-m-d'), '0', null, null, 'draft', $cid);
    LiteAssert::contains('Outstanding balance: 600', $text($a->request('GET', "/finance/invoices/{$id}")['body']));
    LiteAssert::same(1, preg_match('/Balance due\s*600 IRR/', $text($a->request('GET', "/contacts/{$cid}/related/invoices")['body'])), 'contact panel balance due');
    $active = (int) $a->pdo->query("SELECT COUNT(*) FROM modules WHERE `key` = 'client_records' AND status = 'active'")->fetchColumn();
    if ($active === 0) {
        throw new LiteSkip('ClientRecords not installed in crm_local');
    }
    $created = $a->request('POST', '/client-records', [], ['contact_id' => (string) $cid]);
    $record = (int) basename((string) $created['location']);
    LiteAssert::true($record > 0, 'client record created: ' . json_encode($created['status']));
    $overview = $text($a->request('GET', "/client-records/{$record}")['body']);
    LiteAssert::contains('600 IRR', $overview, 'record summary outstanding');
    LiteAssert::notContains('900 IRR', $overview);
    $tab = $text($a->request('GET', "/client-records/{$record}", ['tab' => 'invoices'])['body']);
    LiteAssert::contains('1,000 IRR 100 600 Partially paid', $tab, 'tab: remaining column and status net of credits');
    $api = json_decode($a->request('GET', "/api/v1/client-records/{$record}/financials")['body'], true);
    $rows = array_values(array_filter($api['data'] ?? [], fn ($r) => (int) $r['id'] === $id));
    LiteAssert::same(1, count($rows));
    LiteAssert::same(0, bccomp('900', $rows[0]['remaining'], 18), 'remaining unchanged: grand − paid');
    LiteAssert::same(0, bccomp('600', $rows[0]['outstanding'], 18), 'outstanding: grand − credited − paid');
    LiteAssert::same(['id', 'invoice_number', 'status', 'issue_date', 'currency', 'grand_total', 'paid_total', 'remaining', 'outstanding', 'payments'], array_keys($rows[0]), 'every existing field kept, one added');
    $draft = array_values(array_filter($api['data'], fn ($r) => $r['status'] === 'draft'));
    LiteAssert::same('0', $draft[0]['outstanding'] ?? null, 'a draft owes nothing');
};

$tests['P2-2 fully credited → paid status / zero outstanding everywhere; the contact panel never adds currencies'] = function () use ($boot, $h, $invoice, $contact, $text) {
    $a = $boot();
    $cid = $contact($a);
    $invoice($a, $h->irr, '1000', date('Y-m-d'), '700', '300', null, 'issued', $cid);
    $invoice($a, $h->usd, '0.30', date('Y-m-d'), '0.10', '0.05', null, 'issued', $cid);
    $panel = $text($a->request('GET', "/contacts/{$cid}/related/invoices")['body']);
    LiteAssert::same(1, preg_match('/Balance due\s*0 IRR/', $panel), 'IRR: 1000 − 300 − 700');
    // Assertion changed in the Finance finalization (approved L2 display policy): amounts now show at
    // the currency's precision, so the USD balance 0.30 − 0.10 − 0.05 = 0.15 is shown as it is,
    // instead of the old whole-number display that rounded it to "0" and hid a real balance.
    // The point of the check is unchanged: USD is its own block, never added to IRR.
    LiteAssert::same(1, preg_match('/Balance due\s*0\.15 USD/', $panel), 'USD: its own block, 0.30 − 0.10 − 0.05 at USD precision');
    LiteAssert::same(0, preg_match('/Balance due\s*0\.15 IRR/', $panel), 'the USD balance is never added to IRR');
};

// ---- P3: numeric input ---------------------------------------------------------------------

$tests['P3-1 invoice and proforma lines: "1,000" accepted (same total as 1000); malformed, negative, >100 % and over-gross discounts refused; edge values kept'] = function () use ($boot, $h, $line, $doc, $refused, $is) {
    $a = $boot();
    foreach ([InvoiceService::class, ProformaService::class] as $class) {
        $plain = $doc($a, $class, [$line(['quantity' => '1', 'unit_price' => '1000'])]);
        $comma = $doc($a, $class, [$line(['quantity' => '1', 'unit_price' => '1,000'])]);
        $is((string) $plain['grand_total'], (string) $comma['grand_total'], "{$class}: 1,000 = 1000");
        foreach ([
            [['quantity' => 'abc'], 'Line 1'], [['quantity' => '1e3'], 'Line 1'], [['quantity' => '-2'], 'quantity'],
            [['unit_price' => 'x1'], 'unit price'], [['unit_price' => '-5'], 'unit price'],
            [['discount_type' => 'fixed', 'discount_value' => '-500'], 'discount'],
            [['discount_type' => 'percentage', 'discount_value' => '150'], '100%'],
            [['discount_type' => 'fixed', 'discount_value' => '201'], 'line amount'],
        ] as [$bad, $needle]) {
            $refused(fn () => $doc($a, $class, [$line($bad)]), 'items', $needle);
        }
        $refused(fn () => $doc($a, $class, [$line(), $line(['quantity' => 'bad'])]), 'items', 'Line 2');
        // edges that stay valid: zero quantity, exactly 100 %, fixed discount = gross, no-discount line with an odd value
        $doc($a, $class, [$line(['quantity' => '0'])]);
        $doc($a, $class, [$line(['discount_type' => 'percentage', 'discount_value' => '100'])]);
        $doc($a, $class, [$line(['discount_type' => 'fixed', 'discount_value' => '200'])]);
        $doc($a, $class, [$line(['discount_type' => 'none', 'discount_value' => 'n/a'])]);
    }
};

$tests['P3-2 valid documents unchanged: 2 × 100 with 10 % discount, tax-exclusive, same totals as before the change'] = function () use ($boot, $h, $line, $doc) {
    $a = $boot();
    $inv = $doc($a, InvoiceService::class, [$line(['discount_type' => 'percentage', 'discount_value' => '10']), $line(['quantity' => '1.5', 'unit_price' => '33.333', 'discount_type' => 'fixed', 'discount_value' => '5'])]);
    $items = $a->pdo->query('SELECT quantity, unit_price, discount_value, line_total FROM invoice_items WHERE invoice_id = ' . (int) $inv['id'] . ' ORDER BY position, id')->fetchAll(\PDO::FETCH_ASSOC);
    LiteAssert::same(0, bccomp('180', $items[0]['line_total'], 8), 'line 1: 200 − 10 %');
    LiteAssert::same(0, bccomp('44.9995', $items[1]['line_total'], 8), 'line 2: 49.9995 − 5');
    // Document totals as the pre-change code produced them (verified old vs new): the tax engine rounds.
    LiteAssert::same(0, bccomp('225', (string) $inv['subtotal'], 8), 'subtotal');
    LiteAssert::same(0, bccomp('22.5', (string) $inv['tax_total'], 8), 'tax');
    LiteAssert::same(0, bccomp('247.5', (string) $inv['grand_total'], 8), 'grand total');
};

$tests['P3-3 routes answer 422 (form) / a flash (payment, credit note) — never an uncaught exception'] = function () use ($boot, $h, $line, $invoice) {
    $a = $boot();
    $r = $a->request('POST', '/finance/invoices', [], ['currency_id' => (string) $h->irr, 'pricing_mode' => 'tax_exclusive', 'issue_date' => date('Y-m-d'),
        'items' => [$line(['unit_price' => '1e3'])]]);
    LiteAssert::same(422, $r['status'], 'invoice form');
    LiteAssert::contains('Line 1', html_entity_decode($r['body']));
    $r = $a->request('POST', '/finance/proformas', [], ['currency_id' => (string) $h->irr, 'pricing_mode' => 'tax_exclusive', 'issue_date' => date('Y-m-d'),
        'items' => [$line(['discount_type' => 'fixed', 'discount_value' => '-500'])]]);
    LiteAssert::same(422, $r['status'], 'proforma form');
    $id = $invoice($a, $h->irr, '5000', date('Y-m-d'));
    foreach (['1e3', 'abc', '-5', ''] as $bad) {
        $p = $a->request('POST', "/finance/invoices/{$id}/payments", [], ['amount' => $bad, 'payment_method' => 'cash', 'payment_date' => date('Y-m-d')]);
        LiteAssert::same(302, $p['status'], "payment {$bad}");
        $cn = $a->request('POST', "/finance/invoices/{$id}/credit-notes", [], ['amount' => $bad, 'reason' => 'LiteTest FR', 'issue_date' => date('Y-m-d')]);
        LiteAssert::same(302, $cn['status'], "credit note {$bad}");
    }
    LiteAssert::same([0, 0], [(int) $a->pdo->query("SELECT COUNT(*) FROM payments WHERE invoice_id = {$id}")->fetchColumn(), (int) $a->pdo->query("SELECT COUNT(*) FROM credit_notes WHERE invoice_id = {$id}")->fetchColumn()], 'nothing written');
};

$tests['P3-4 (A4) payments and credit notes: "1e3" / "abc" / negative / "" / "1.2.3" refused before any write (in-suite)'] = function () use ($boot, $h, $invoice, $payments, $credits, $refused) {
    $a = $boot();
    $id = $invoice($a, $h->irr, '5000', date('Y-m-d'));
    foreach (['1e3', 'abc', '-5', '', '1.2.3', '0'] as $bad) {
        $refused(fn () => $payments($a)->record($a->admin, $id, ['amount' => $bad, 'payment_method' => 'cash', 'payment_date' => date('Y-m-d')]), 'amount', 'positive number');
        $refused(fn () => $credits($a)->issue($a->admin, $id, ['amount' => $bad, 'reason' => 'LiteTest FR', 'issue_date' => date('Y-m-d')]), 'amount', 'positive number');
    }
    // closed periods still refused with a normalized amount (checked before any transaction)
    $a->pdo->prepare("INSERT INTO financial_periods (organization_id, starts_on, ends_on, status) VALUES (?, '2001-01-01', '2001-12-31', 'closed')")->execute([$a->orgId]);
    $refused(fn () => $payments($a)->record($a->admin, $id, ['amount' => '1,000', 'payment_method' => 'cash', 'payment_date' => '2001-05-05']), 'payment_date', 'closed');
    $refused(fn () => $credits($a)->issue($a->admin, $id, ['amount' => '1,000', 'reason' => 'LiteTest FR', 'issue_date' => '2001-05-05']), 'issue_date', 'closed');
    LiteAssert::same([0, 0], [(int) $a->pdo->query("SELECT COUNT(*) FROM payments WHERE invoice_id = {$id}")->fetchColumn(), (int) $a->pdo->query("SELECT COUNT(*) FROM credit_notes WHERE invoice_id = {$id}")->fetchColumn()]);
    LiteAssert::same('1000', \Modules\Finance\AmountInput::normalize('1,000'));
    LiteAssert::same('1500.50', \Modules\Finance\AmountInput::normalize(' 1,500.50 '));
    foreach (['1e3', '-1', '+1', '1.2.3', '0x10', '', '١٠٠', [1]] as $bad) {
        LiteAssert::same(null, \Modules\Finance\AmountInput::normalize($bad), 'malformed: ' . json_encode($bad, JSON_UNESCAPED_UNICODE));
    }
};

$tests['P3-5 payments and credit notes, success paths (own transactions → child process, autocommit, cleaned up): "1,000" stored as 1000; outstanding / creditable caps unchanged'] = function () use ($boot) {
    $a = $boot();
    // Stage 5.2 (L5): payments / credit notes now take a shared lock on the period rows; this
    // suite's open transaction holds row locks on them (its boot removes closed periods, P3-4 adds
    // one), which correctly blocks the child. Release them first — earlier tests' data is only
    // rolled back (as the last test does anyway); assertions are unchanged.
    $a->rollBack();
    $json = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/standalone/finance-amounts-child.php') . ' 2>&1');
    $r = json_decode(trim($json), true);
    LiteAssert::true(is_array($r), 'child output: ' . substr($json, 0, 300));
    LiteAssert::same(['accepted', 'accepted'], [$r['payment_1,000'], $r['credit_1,500.50']]);
    LiteAssert::same(0, bccomp('1000', $r['paid_total'], 18), 'paid_total');
    LiteAssert::same(0, bccomp('1000', $r['payment_amount'], 18), 'payment row');
    LiteAssert::same(0, bccomp('1500.5', $r['credit_amount'], 18), 'credit note row');
    LiteAssert::contains('refused:amount', $r['payment_over_cap'], 'payment above outstanding (2,499.5)');
    LiteAssert::contains('refused:amount', $r['credit_over_cap'], 'credit above creditable (3,499.5)');
    LiteAssert::same('accepted', $r['payment_exact_cap'], 'exactly the outstanding');
    LiteAssert::same(0, bccomp('3499.5', $r['final_paid_total'], 18));
    LiteAssert::same(0, $r['leftover'], 'child cleaned up');
};

$tests['rollback leaves no test data behind'] = function () use ($boot) {
    $a = $boot();
    $a->rollBack();
    LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM invoices WHERE notes = 'LiteTest FR'")->fetchColumn());
    LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM credit_notes WHERE reason = 'LiteTest FR'")->fetchColumn());
    LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM invoice_items WHERE description = 'LiteTest FR line'")->fetchColumn());
    LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM contacts WHERE first_name = 'LiteTest FR'")->fetchColumn());
    LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM roles WHERE `key` LIKE 'litetest_fr_%'")->fetchColumn());
};

return $tests;
