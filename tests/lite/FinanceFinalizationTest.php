<?php

declare(strict_types=1);

/**
 * Stage 5.2 Finance finalization — L2/L3 precision and optional final invoice
 * rounding (migration 0024), L4 financial audit events.
 *
 * In-suite (one rolled-back transaction): input precision per currency (IRR 3,
 * USD 2, unit price + 2, quantity 4, percentage 4), totals at the currency's
 * precision, display through money(), the organization rounding setting, the
 * rounding snapshot on the invoice page / print, and who may see the financial
 * history (payments.view / credit_notes.view / financial_periods.view) — and
 * that no other timeline shows it.
 *
 * Issuing, payments, credit notes and period close / reopen open their own
 * transactions → child process (tests/lite/standalone/finance-finalization-child.php),
 * through the real routes, cleaned up, number series restored.
 *
 * Skipped (with the reason) until migration 0024 has been applied to crm_local.
 */

require_once __DIR__ . '/Support/LocalApp.php';

use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Finance\CurrencyPrecision;
use Modules\Finance\Money;
use Modules\Finance\Services\InvoiceService;
use Modules\Finance\Services\ProformaService;
use Tests\Lite\Support\LocalApp;

use function App\Infrastructure\Theme\money;

$h = new \stdClass();
$h->app = null;
$boot = function () use ($h): LocalApp {
    if ($h->app !== null) {
        return $h->app;
    }
    $a = LocalApp::boot();
    if ($a->pdo->query("SHOW COLUMNS FROM invoices LIKE 'unrounded_total'")->fetchColumn() === false) {
        $a->rollBack();
        throw new LiteSkip('Finance migration 0024 (final invoice rounding) not applied to crm_local yet');
    }
    if ($a->member === null) {
        $a->rollBack();
        throw new LiteSkip('needs a second active user in the organization');
    }
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
$timeline = fn (LocalApp $a) => $c($a)->make(\App\Application\Services\TimelineService::class);
$periods = fn (LocalApp $a) => new \Modules\Finance\Services\FinancialPeriodService(new \Modules\Finance\FinancialPeriodRepository(), $auth($a), $timeline($a));
$payments = fn (LocalApp $a) => new \Modules\Finance\Services\PaymentService(new \Modules\Finance\PaymentRepository(), new \Modules\Finance\InvoiceRepository(), new \Modules\Finance\CreditNoteRepository(), $auth($a), $periods($a), timeline: $timeline($a));
$credits = fn (LocalApp $a) => new \Modules\Finance\Services\CreditNoteService(new \Modules\Finance\CreditNoteRepository(), new \Modules\Finance\CreditNoteNumberSeriesRepository(), new \Modules\Finance\InvoiceRepository(), $auth($a), $periods($a), timeline: $timeline($a));
$expenses = fn (LocalApp $a) => new \Modules\Finance\Services\ExpenseService(new \Modules\Finance\ExpenseRepository(), $auth($a), $timeline($a),
    $c($a)->make(\App\Application\Services\AttachmentService::class), $c($a)->make(\App\Infrastructure\Records\RecordTypeRegistry::class));
$line = fn (array $over = []): array => $over + ['description' => 'LiteTest FIN line', 'quantity' => '1', 'unit_price' => '100', 'discount_type' => 'none', 'discount_value' => '0'];
$doc = fn (LocalApp $a, string $class, int $currency, array $lines, array $header = []) => $c($a)->make($class)->create($a->admin, $header + ['currency_id' => (string) $currency, 'pricing_mode' => 'tax_exclusive', 'issue_date' => date('Y-m-d'), 'items' => $lines]);
/** A stored issued invoice (no service side effects); $extra sets any other column (e.g. the rounding snapshot). */
$invoice = function (LocalApp $a, int $currency, string $grand, array $extra = [], ?int $contactId = null): int {
    $cols = ['organization_id' => $a->orgId, 'contact_id' => $contactId, 'owner_id' => (int) $a->admin['id'], 'issue_date' => date('Y-m-d'), 'currency_id' => $currency,
        'subtotal' => $grand, 'tax_total' => '0', 'grand_total' => $grand, 'paid_total' => '0', 'status' => 'issued', 'notes' => 'LiteTest FIN'] + [];
    $cols = array_merge($cols, $extra);
    $a->pdo->prepare('INSERT INTO invoices (' . implode(', ', array_keys($cols)) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')->execute(array_values($cols));

    return (int) $a->pdo->lastInsertId();
};
$event = fn (LocalApp $a, string $subject, int $id, string $type, string $description) => $a->pdo->prepare('INSERT INTO timeline_events (organization_id, subject_type, subject_id, type, description, actor_user_id) VALUES (?, ?, ?, ?, ?, ?)')
    ->execute([$a->orgId, $subject, $id, $type, $description, (int) $a->admin['id']]);
$userWith = function (LocalApp $a, array $keys): array {
    $a->pdo->prepare('INSERT INTO roles (organization_id, `key`, name, is_system) VALUES (?, ?, ?, 0)')->execute([$a->orgId, 'litetest_fin_' . bin2hex(random_bytes(4)), 'LiteTest FIN']);
    $role = (int) $a->pdo->lastInsertId();
    foreach ($keys as $key) {
        $a->pdo->prepare("INSERT INTO role_permissions (role_id, permission_key, scope) VALUES (?, ?, 'organization')")->execute([$role, $key]);
    }

    return array_merge($a->member, ['role_id' => $role]);
};
$text = fn (string $html): string => preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html)));
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
    throw new LiteAssertionFailed("expected a refusal on {$field}");
};
$is = fn (string $expected, string $actual, string $msg = '') => LiteAssert::same(0, bccomp($expected, $actual, 18), "{$msg}: expected {$expected}, got {$actual}");

$tests = [];

// ---- L2: the precision policy -------------------------------------------------------------

$tests['PREC-1 policy table: IRR 3 (never 0), USD 2, unit price = money + 2, quantity 4, percentage 4, IRR final rounding 0'] = function () use ($boot, $h) {
    $boot();
    LiteAssert::same([3, 2], [Money::decimalsFor($h->irr), Money::decimalsFor($h->usd)]);
    LiteAssert::same([5, 4], [Money::unitPriceDecimalsFor($h->irr), Money::unitPriceDecimalsFor($h->usd)]);
    LiteAssert::same([0, 2], [Money::finalRoundingDecimalsFor($h->irr), Money::finalRoundingDecimalsFor($h->usd)]);
    LiteAssert::same([4, 4], [Money::QUANTITY_DECIMALS, Money::PERCENT_DECIMALS]);
    LiteAssert::same([0, 1, 3, 0], [Money::fractionDigits('100'), Money::fractionDigits('1.500'), Money::fractionDigits('0.125'), Money::fractionDigits('7.000000000000000000')], 'significant digits only');
    LiteAssert::same(['1.001', '1.01', '-0.5', '2'], [Money::round('1.0005', 3), Money::round('1.005', 2), Money::round('-0.45', 1), Money::round('1.5', 0)], 'half-up (away from zero) — the implemented mode');
};

$tests['PREC-2 invoice and proforma lines: precision limits accepted at the edge, one digit more refused (IRR and USD)'] = function () use ($boot, $h, $doc, $line, $refused) {
    $a = $boot();
    foreach ([InvoiceService::class, ProformaService::class] as $class) {
        // at the limit: accepted
        $doc($a, $class, $h->irr, [$line(['quantity' => '1.1234', 'unit_price' => '0.12345'])]);
        $doc($a, $class, $h->usd, [$line(['quantity' => '2', 'unit_price' => '9.9999'])]);
        $doc($a, $class, $h->irr, [$line(['discount_type' => 'percentage', 'discount_value' => '10.1234'])]);
        $doc($a, $class, $h->irr, [$line(['discount_type' => 'fixed', 'discount_value' => '1.123'])]);
        $doc($a, $class, $h->usd, [$line(['discount_type' => 'fixed', 'discount_value' => '1.12'])]);
        $doc($a, $class, $h->irr, [$line(['unit_price' => '1,000'])]);
        // one more digit: refused, never truncated
        $refused(fn () => $doc($a, $class, $h->irr, [$line(['quantity' => '1.12345'])]), 'items', 'quantity can have at most 4');
        $refused(fn () => $doc($a, $class, $h->irr, [$line(['unit_price' => '0.123456'])]), 'items', 'unit price can have at most 5');
        $refused(fn () => $doc($a, $class, $h->usd, [$line(['unit_price' => '9.99999'])]), 'items', 'unit price can have at most 4');
        $refused(fn () => $doc($a, $class, $h->irr, [$line(['discount_type' => 'percentage', 'discount_value' => '10.12345'])]), 'items', 'discount can have at most 4');
        $refused(fn () => $doc($a, $class, $h->irr, [$line(['discount_type' => 'fixed', 'discount_value' => '1.1234'])]), 'items', 'discount can have at most 3');
        $refused(fn () => $doc($a, $class, $h->usd, [$line(['discount_type' => 'fixed', 'discount_value' => '1.123'])]), 'items', 'discount can have at most 2');
        // P3 behaviour kept
        $refused(fn () => $doc($a, $class, $h->irr, [$line(['unit_price' => '1e3'])]), 'items');
        $refused(fn () => $doc($a, $class, $h->irr, [$line(['quantity' => '-1'])]), 'items');
    }
};

$tests['PREC-3 invoice-level discount: currency precision, never negative, never above the lines'] = function () use ($boot, $h, $doc, $line, $refused, $is) {
    $a = $boot();
    foreach ([InvoiceService::class, ProformaService::class] as $class) {
        $ok = $doc($a, $class, $h->irr, [$line()], ['invoice_discount' => '1.125']);
        $is('1.125', (string) $ok['invoice_discount'], "{$class}: IRR 3 decimals kept");
        $is('1.5', (string) $doc($a, $class, $h->usd, [$line()], ['invoice_discount' => '1.50'])['invoice_discount'], "{$class}: USD 2 decimals kept");
        $refused(fn () => $doc($a, $class, $h->irr, [$line()], ['invoice_discount' => '1.1255']), 'invoice_discount', 'At most 3');
        $refused(fn () => $doc($a, $class, $h->usd, [$line()], ['invoice_discount' => '1.125']), 'invoice_discount', 'At most 2');
        $refused(fn () => $doc($a, $class, $h->irr, [$line()], ['invoice_discount' => '-1']), 'invoice_discount');
        $refused(fn () => $doc($a, $class, $h->irr, [$line()], ['invoice_discount' => '1e2']), 'invoice_discount');
        $refused(fn () => $doc($a, $class, $h->irr, [$line()], ['invoice_discount' => '100.001']), 'invoice_discount', 'more than the sum of the lines');
    }
};

$tests['PREC-4 totals: exact line totals, subtotal and grand total at the currency precision (half-up), IRR fractions kept'] = function () use ($boot, $h, $doc, $line, $is) {
    $a = $boot();
    foreach ([InvoiceService::class, ProformaService::class] as $class) {
        $irr = $doc($a, $class, $h->irr, [$line(['quantity' => '1', 'unit_price' => '1.0005'])]);
        $is('1.001', (string) $irr['subtotal'], "{$class}: IRR subtotal to 3 decimals, not to a whole rial");
        $usd = $doc($a, $class, $h->usd, [$line(['quantity' => '1', 'unit_price' => '1.005'])]);
        $is('1.01', (string) $usd['subtotal'], "{$class}: USD subtotal to 2 decimals");
        LiteAssert::true(Money::fractionDigits((string) $irr['grand_total']) <= 3 && Money::fractionDigits((string) $usd['grand_total']) <= 2, 'grand totals at the currency precision');
        $items = $a->pdo->query('SELECT line_total FROM ' . ($class === InvoiceService::class ? 'invoice_items WHERE invoice_id = ' : 'proforma_items WHERE proforma_id = ') . (int) $irr['id'])->fetchColumn();
        $is('1.0005', (string) $items, "{$class}: the line total itself is stored exactly");
        $plain = $doc($a, $class, $h->irr, [$line(['quantity' => '2', 'unit_price' => '100', 'discount_type' => 'percentage', 'discount_value' => '10'])]);
        $is('180', (string) $plain['subtotal'], "{$class}: whole-number documents unchanged");
    }
};

$tests['PREC-5 payments, credit notes and expenses: at most the currency precision (IRR 3, USD 2) — refused, never truncated'] = function () use ($boot, $h, $invoice, $payments, $credits, $expenses, $refused) {
    $a = $boot();
    $irr = $invoice($a, $h->irr, '1000');
    $usd = $invoice($a, $h->usd, '100');
    $pay = fn (int $id, string $amount) => $payments($a)->record($a->admin, $id, ['amount' => $amount, 'payment_method' => 'cash', 'payment_date' => date('Y-m-d')]);
    $credit = fn (int $id, string $amount) => $credits($a)->issue($a->admin, $id, ['amount' => $amount, 'reason' => 'LiteTest FIN', 'issue_date' => date('Y-m-d')]);
    $refused(fn () => $pay($irr, '1.0001'), 'amount', 'At most 3');
    $refused(fn () => $pay($usd, '1.001'), 'amount', 'At most 2');
    $refused(fn () => $credit($irr, '1.0001'), 'amount', 'At most 3');
    $refused(fn () => $credit($usd, '1.001'), 'amount', 'At most 2');
    $refused(fn () => $pay($irr, '1e2'), 'amount');
    $refused(fn () => $credit($irr, '-5'), 'amount');
    $e = fn (int $currency, string $amount) => $expenses($a)->create($a->admin, ['category' => 'other', 'description' => 'LiteTest FIN', 'expense_date' => date('Y-m-d'), 'amount' => $amount, 'currency_id' => (string) $currency]);
    $e($h->irr, '1.125');
    $e($h->usd, '1.25');
    $e($h->irr, '1,500');
    $refused(fn () => $e($h->irr, '1.1255'), 'amount', 'At most 3');
    $refused(fn () => $e($h->usd, '1.255'), 'amount', 'At most 2');
    LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM payments WHERE invoice_id IN ({$irr}, {$usd})")->fetchColumn(), 'nothing written by a refusal');
};

$tests['DISP-1 money(): IRR significant decimals only (≤ 3), other currencies their fixed precision, half-up, never a cross-currency sum'] = function () use ($boot) {
    $boot();
    LiteAssert::same('1,500', money('1500.000000000000000000', 'IRR'));
    LiteAssert::same('1,500.5', money('1500.500000000000000000', 'IRR'));
    LiteAssert::same('0.125', money('0.125', 'IRR'));
    LiteAssert::same('0.124', money('0.1235', 'IRR'), 'IRR rounds to 3 half-up for display');
    LiteAssert::same('10.00', money('10', 'USD'));
    LiteAssert::same('0.15', money('0.150000000000000000', 'USD'));
    LiteAssert::same('-0.40', money('-0.4', 'USD'));
    LiteAssert::same([3, 0, 2], [
        CurrencyPrecision::displayDecimals('1.1239', CurrencyPrecision::currencyByCode('IRR')),
        CurrencyPrecision::displayDecimals('7.0004', CurrencyPrecision::currencyByCode('IRR')),
        CurrencyPrecision::displayDecimals('7', CurrencyPrecision::currencyByCode('USD')),
    ]);
};

$tests['DISP-2 invoice page, list and print show fractional amounts (the old whole-number display hid them)'] = function () use ($boot, $h, $invoice, $text) {
    $a = $boot();
    $id = $invoice($a, $h->irr, '1500.5', ['subtotal' => '1500.5']);
    $page = $text($a->request('GET', "/finance/invoices/{$id}")['body']);
    LiteAssert::contains('1,500.5', $page);
    $usd = $invoice($a, $h->usd, '10.5');
    LiteAssert::contains('10.50', $text($a->request('GET', "/finance/invoices/{$usd}")['body']));
    LiteAssert::contains('1,500.5', $text($a->request('GET', "/finance/invoices/{$id}/print")['body']));
};

// ---- L2/L3: optional final invoice rounding (migration 0024) -----------------------------

$tests['ROUND-1 organization setting: OFF by default, saved from the settings form (checkbox), shown on the form'] = function () use ($boot, $auth, $userWith) {
    $a = $boot();
    $profiles = new \Modules\Finance\Services\FinancialProfileService(new \Modules\Finance\FinancialProfileRepository(), $auth($a));
    $current = $profiles->get($a->admin);
    LiteAssert::same(0, (int) $current['invoice_final_rounding'], 'existing organizations: OFF (migration default)');
    $base = array_intersect_key($current, array_flip(['entity_type', 'legal_name', 'first_name', 'last_name', 'tax_identification_number', 'country_id', 'tax_jurisdiction_id', 'tax_profile_id', 'base_currency_id', 'invoice_prefix', 'pricing_mode']));
    $base = array_map(fn ($v) => $v ?? '', $base);
    $base['legal_name'] = $base['legal_name'] !== '' ? $base['legal_name'] : 'LiteTest FIN';
    $base['first_name'] = $base['first_name'] !== '' ? $base['first_name'] : 'LiteTest';
    LiteAssert::same(302, $a->request('POST', '/finance/settings', [], $base + ['invoice_final_rounding' => '1'])['status']);
    LiteAssert::same(1, (int) $profiles->get($a->admin)['invoice_final_rounding']);
    LiteAssert::contains('name="invoice_final_rounding" value="1" checked', $a->request('GET', '/finance/settings')['body']);
    LiteAssert::same(302, $a->request('POST', '/finance/settings', [], $base)['status'], 'unticked checkbox');
    LiteAssert::same(0, (int) $profiles->get($a->admin)['invoice_final_rounding']);
    // The setting stays behind finance.settings.edit (RBAC unchanged).
    $a->as($userWith($a, ['invoices.view', 'invoices.issue']));
    LiteAssert::same('forbidden', $a->outcome('POST', '/finance/settings', [], $base + ['invoice_final_rounding' => '1']));
    $a->as($a->admin);
    LiteAssert::same(0, (int) $profiles->get($a->admin)['invoice_final_rounding']);
};

$tests['ROUND-2 issue form: the per-invoice choice defaults to the organization setting and names the target precision'] = function () use ($boot, $h, $invoice, $text) {
    $a = $boot();
    $draft = $invoice($a, $h->irr, '1000.4', ['status' => 'draft']);
    $body = $a->request('GET', "/finance/invoices/{$draft}")['body'];
    LiteAssert::contains('name="final_rounding" value="0"', $body, 'a hidden 0, so an unticked box is an explicit "no"');
    LiteAssert::same(0, preg_match('/name="final_rounding" value="1" checked/', $body), 'unchecked while the organization default is OFF');
    LiteAssert::contains('(0 decimals)', $text($body), 'IRR → whole rial');
    $a->pdo->prepare('UPDATE financial_profiles SET invoice_final_rounding = 1 WHERE organization_id = ?')->execute([$a->orgId]);
    LiteAssert::contains('name="final_rounding" value="1" checked', $a->request('GET', "/finance/invoices/{$draft}")['body'], 'checked when the default is ON');
    $usd = $invoice($a, $h->usd, '10.555', ['status' => 'draft']);
    LiteAssert::contains('(2 decimals)', $text($a->request('GET', "/finance/invoices/{$usd}")['body']));
    $a->pdo->prepare('UPDATE financial_profiles SET invoice_final_rounding = 0 WHERE organization_id = ?')->execute([$a->orgId]);
};

$tests['ROUND-3 snapshot shown as stored: before rounding, adjustment, payable — invoice page and print; legacy invoices show none'] = function () use ($boot, $h, $invoice, $text, $c) {
    $a = $boot();
    $rounded = $invoice($a, $h->irr, '1000', ['final_rounding' => 1, 'rounding_precision' => 0, 'unrounded_total' => '1000.4', 'rounding_adjustment' => '-0.4', 'subtotal' => '1000.4']);
    $page = $text($a->request('GET', "/finance/invoices/{$rounded}")['body']);
    LiteAssert::same(1, preg_match('/Total before rounding\s*1,000\.4/', $page), 'before rounding');
    LiteAssert::same(1, preg_match('/Rounding adjustment\s*-0\.4/', $page), 'adjustment');
    LiteAssert::same(1, preg_match('/Outstanding balance:\s*1,000(?![.\d])/', $page), 'outstanding from the final payable (1,000, not 1,000.4)');
    $print = $text($a->request('GET', "/finance/invoices/{$rounded}/print")['body']);
    LiteAssert::same(1, preg_match('/Total before rounding\s*1,000\.4/', $print), 'print: before rounding');
    LiteAssert::same(1, preg_match('/Rounding adjustment\s*-0\.4/', $print), 'print: adjustment');
    $legacy = $invoice($a, $h->irr, '1000.4');
    $legacyPage = $text($a->request('GET', "/finance/invoices/{$legacy}")['body']);
    LiteAssert::same(false, str_contains($legacyPage, 'Total before rounding'), 'issued before the feature: nothing invented');
    LiteAssert::same(false, str_contains($text($a->request('GET', "/finance/invoices/{$legacy}/print")['body']), 'Total before rounding'), 'print: nothing invented either');
    $notRounded = $invoice($a, $h->irr, '1000.4', ['final_rounding' => 0, 'unrounded_total' => '1000.4', 'rounding_adjustment' => '0']);
    LiteAssert::same(false, str_contains($text($a->request('GET', "/finance/invoices/{$notRounded}")['body']), 'Rounding adjustment'));
};

// ---- L4: financial audit events — visibility ----------------------------------------------

$tests['L4-1 invoice financial history: payment events only with payments.view, credit-note events only with credit_notes.view'] = function () use ($boot, $h, $invoice, $event, $userWith, $text) {
    $a = $boot();
    $id = $invoice($a, $h->irr, '1000');
    $event($a, 'invoice', $id, 'payment_recorded', 'Payment #1: 600 (cash) LTFIN-PAY');
    $event($a, 'invoice', $id, 'credit_note_issued', 'Credit note CN-LT: 400 LTFIN-CN');
    $see = function (array $user) use ($a, $id, $text): array {
        $a->as($user);
        $page = $text($a->request('GET', "/finance/invoices/{$id}")['body']);
        $a->as($a->admin);

        return [str_contains($page, 'LTFIN-PAY'), str_contains($page, 'LTFIN-CN')];
    };
    LiteAssert::same([true, true], $see($a->admin));
    LiteAssert::same([false, false], $see($userWith($a, ['invoices.view'])), 'invoice page without payment / credit-note permissions');
    LiteAssert::same([true, false], $see($userWith($a, ['invoices.view', 'payments.view'])));
    LiteAssert::same([false, true], $see($userWith($a, ['invoices.view', 'credit_notes.view'])));
    $a->as($userWith($a, ['payments.view', 'credit_notes.view']));
    LiteAssert::true(in_array($a->outcome('GET', "/finance/invoices/{$id}"), ['forbidden', 'notfound'], true), 'no invoices.view → no invoice page at all');
    $a->as($a->admin);
};

$tests['L4-2 period close / reopen history: financial_periods.view only (service and page)'] = function () use ($boot, $periods, $event, $userWith, $text) {
    $a = $boot();
    $a->pdo->prepare("INSERT INTO financial_periods (organization_id, starts_on, ends_on, status) VALUES (?, '1984-01-01', '1984-12-31', 'open')")->execute([$a->orgId]);
    $p = (int) $a->pdo->lastInsertId();
    $event($a, 'financial_period', $p, 'period_closed', 'Financial period 1984-01-01 – 1984-12-31 closed');
    $event($a, 'financial_period', $p, 'period_reopened', 'Financial period 1984-01-01 – 1984-12-31 reopened');
    LiteAssert::same(2, count($periods($a)->history($a->admin, [$p])[$p] ?? []));
    LiteAssert::throws(AuthorizationException::class, fn () => $periods($a)->history($userWith($a, ['invoices.view', 'payments.view']), [$p]));
    $page = $text($a->request('GET', '/finance/periods')['body']);
    LiteAssert::contains('Period closed', $page);
    LiteAssert::contains('Period reopened', $page);
    $a->as($userWith($a, ['invoices.view', 'payments.view', 'credit_notes.view']));
    LiteAssert::same('forbidden', $a->outcome('GET', '/finance/periods'));
    $a->as($a->admin);
};

$tests['L4-3 no leak: financial events never appear on the contact page, its related panel or the ClientRecords timeline'] = function () use ($boot, $h, $invoice, $event, $c) {
    $a = $boot();
    $cid = (int) $c($a)->make(\App\Application\Services\ContactService::class)->create($a->admin, ['first_name' => 'LiteTest FIN', 'last_name' => bin2hex(random_bytes(3))])['id'];
    $id = $invoice($a, $h->irr, '1000', [], $cid);
    $event($a, 'invoice', $id, 'payment_recorded', 'LTFIN-LEAK-PAY');
    $event($a, 'invoice', $id, 'credit_note_issued', 'LTFIN-LEAK-CN');
    foreach (["/contacts/{$cid}", "/contacts/{$cid}/related/invoices"] as $path) {
        $body = $a->request('GET', $path)['body'];
        LiteAssert::same(false, str_contains($body, 'LTFIN-LEAK'), "{$path} shows no financial audit text");
    }
    // The ClientRecords timeline reads only the contact / client_record subjects (code: ClientTimelineService).
    $src = (string) file_get_contents(dirname(__DIR__, 2) . '/modules/ClientRecords/Services/ClientTimelineService.php');
    LiteAssert::same(0, preg_match("/forSubject\\('(invoice|financial_period)'/", $src), 'ClientRecords never reads invoice / period events');
    $readers = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/modules')) as $f) {
        if ($f->isFile() && str_ends_with($f->getFilename(), '.php') && preg_match("/forSubject\\('(invoice|financial_period)'/", (string) file_get_contents($f->getPathname())) === 1) {
            $readers[] = basename(dirname($f->getPathname())) . '/' . $f->getFilename();
        }
    }
    sort($readers);
    LiteAssert::same(['Controllers/InvoiceController.php', 'Services/FinancialPeriodService.php'], $readers, 'the only readers: the permission-filtered invoice page and period history');
};

// ---- Issuing, payments, credit notes, periods: own transactions → child process ---------------

$tests['CHILD rounding at issue, the adjustment counted once, L4 events in the same transaction (child process, cleaned up)'] = function () use ($boot) {
    $a = $boot();
    $a->rollBack(); // release this suite's locks before the child writes (see FinanceRemediationTest P3-5)
    $json = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/standalone/finance-finalization-child.php') . ' 2>&1');
    $r = json_decode(trim($json), true);
    LiteAssert::true(is_array($r), 'child output: ' . substr($json, 0, 400));
    if ($r['skip'] !== null) {
        throw new LiteSkip($r['skip']);
    }
    // R — [status, grand (payable), unrounded, adjustment, final_rounding, precision]
    LiteAssert::same(302, $r['r1_status']);
    LiteAssert::same(['issued', '1000', '1000.4', '-0.4', 1, 0], $r['r1'], 'rounded on request: whole rial, pre-round amount and adjustment kept');
    LiteAssert::same(['issued', '1000.4', '1000.4', '0', 0, null], $r['r2'], 'explicitly not rounded: decision recorded, nothing changed');
    LiteAssert::same(['issued', '1001', '1000.5', '0.5', 1, 0], $r['r3'], 'half-up');
    LiteAssert::same(['issued', '2000', '2000.25', '-0.25', 1, 0], $r['r4'], 'organization default ON');
    LiteAssert::same(['issued', '2000.25', '2000.25', '0', 0, null], $r['r6'], 'per-invoice override of the ON default');
    LiteAssert::same(['issued', '2000.25', '2000.25', '0', 0, null], $r['r5'], 'organization default OFF');
    LiteAssert::same(['issued', '10.56', '10.555', '0.005', 1, 2], $r['u1'], 'USD → its configured precision');
    LiteAssert::same(['draft', '7.7', null, '0', null, null], $r['draft_untouched'], 'drafts carry no snapshot');
    foreach (['Total before rounding', 'Rounding adjustment', '1,000.4', '-0.4'] as $needle) {
        LiteAssert::contains($needle, $r['issued_page']);
        LiteAssert::contains($needle, $r['print_page']);
    }
    LiteAssert::same(false, str_contains($r['plain_page'], 'Rounding adjustment'));
    // E — exactly once: payable 1000 (not 1000.4) everywhere
    LiteAssert::same([302, 0], [$r['pay_unrounded'], $r['payments_after_refusal']], 'paying the pre-round amount is over the payable → refused');
    LiteAssert::same([302, 0], [$r['credit_over'], $r['credit_notes_after_refusal']], 'crediting the pre-round amount is over the creditable → refused');
    LiteAssert::same([1, 1], [$r['payments'], $r['payment_events']], 'one payment, one audit event, even when submitted twice');
    LiteAssert::same([1, 1], [$r['credit_notes'], $r['credit_events']], 'one credit note, one audit event, even when submitted twice');
    LiteAssert::contains('600 (bank_transfer), dated 1981-03-11 — paid 0 → 600', $r['payment_event_text']);
    LiteAssert::same(true, $r['payment_event_actor']);
    LiteAssert::contains(': 400, dated 1981-03-12 — credited 0 → 400', $r['credit_event_text']);
    LiteAssert::same(['paid', '0'], [$r['r1_status_after'], $r['r1_outstanding']], '1000 − 400 − 600: settled, no 0.4 left over');
    LiteAssert::contains('Payment recorded', $r['history_page']);
    LiteAssert::contains('Credit note issued', $r['history_page']);
    // report on stored values: 1000 + 1000.4 + 1001 + 2000 + 2000.25 + 2000.25; USD never added
    LiteAssert::same(['gross_revenue' => '9001.9', 'total_credited' => '400', 'net_revenue' => '8601.9', 'total_paid' => '600', 'outstanding' => '8001.9'], $r['report']);
    LiteAssert::same(false, $r['report_usd'], 'the USD invoice is in April — and never summed with IRR');
    // T — the event insert fails → the record is rolled back with it
    LiteAssert::same(['refused:retry', 'refused:retry', 'refused:retry'], [$r['atomic_payment'], $r['atomic_credit'], $r['atomic_close']]);
    LiteAssert::same([0, '0', 0, true, 'open', 0], $r['atomic_rows'], 'no payment, no paid_total change, no credit note, no number consumed, period still open, no event');
    // P — close / reopen
    LiteAssert::same([302, 302, [1, 1], 2], [$r['close_status'], $r['reopen_status'], $r['period_events'], $r['period_actor']]);
    LiteAssert::contains('Period closed', $r['periods_page']);
    LiteAssert::same(2, $r['period_events_after'], 'a refused reopen writes no event');
    // hygiene
    LiteAssert::same([true, true], $r['series_restored'], 'invoice and credit-note series back to their values');
    LiteAssert::same(true, $r['rounding_restored']);
    LiteAssert::same(true, $r['legacy_unchanged'], 'existing issued invoices: no backfill, no recalculation');
    LiteAssert::same(0, $r['leftover']);
};

$tests['rollback leaves no test data behind'] = function () use ($boot) {
    $a = $boot();
    $a->rollBack();
    LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM invoices WHERE notes IN ('LiteTest FIN', 'LiteTest FIN child')")->fetchColumn());
    LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM roles WHERE `key` LIKE 'litetest_fin_%'")->fetchColumn());
    LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM finance_expenses WHERE description = 'LiteTest FIN'")->fetchColumn());
    LiteAssert::same(0, (int) $a->pdo->query("SELECT invoice_final_rounding FROM financial_profiles WHERE organization_id = " . (int) $a->orgId)->fetchColumn());
};

return $tests;
