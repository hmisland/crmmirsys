<?php

declare(strict_types=1);

/**
 * Invoices for the tax system: the invoice type (1/2/3, type 1 needs a
 * buyer with a national ID or economic code) and the CSV export of issued
 * invoices (one row per item, tax per row from the product, warnings that
 * never block the file). crm_local, one rolled-back transaction.
 */

use Tests\Lite\Support\LocalApp;

require_once __DIR__ . '/Support/LocalApp.php';

$h = new \stdClass();
$h->ready = false;
$h->reason = '';

$setup = function () use ($h): void {
    if ($h->ready) {
        return;
    }
    if ($h->reason !== '') {
        throw new LiteSkip($h->reason);
    }
    try {
        $h->a = LocalApp::boot();
        $h->a->pdo->query('SELECT tax_invoice_type FROM invoices LIMIT 1');
        $h->a->pdo->query('SELECT tax_product_code FROM products LIMIT 1');
    } catch (\Throwable $e) {
        throw new LiteSkip($h->reason = 'local database unavailable or finance 0025-0027 not migrated: ' . $e->getMessage());
    }
    $a = $h->a;
    $a->locale('en');
    $pdo = $a->pdo;
    $h->irr = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'IRR'")->fetchColumn();
    $h->usd = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'USD'")->fetchColumn();
    $contact = function (string $last, ?string $nationalId) use ($pdo, $a): int {
        $pdo->prepare("INSERT INTO contacts (organization_id, first_name, last_name, national_id, postal_code, status, created_at, updated_at) VALUES (?, 'Lite', ?, ?, '1234567890', 'active', NOW(), NOW())")
            ->execute([$a->orgId, $last, $nationalId]);

        return (int) $pdo->lastInsertId();
    };
    $h->buyer = $contact('Buyer', '0012345678');
    $h->anon = $contact('NoId', null);
    $product = function (string $name, ?string $code, ?string $sku, ?string $rate, ?int $exempt, ?string $unit) use ($pdo, $a, $h): int {
        $pdo->prepare('INSERT INTO products (organization_id, sku, tax_product_code, name, unit, price, currency_id, vat_exempt, vat_rate, is_active, created_at) VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, 1, NOW())')
            ->execute([$a->orgId, $sku, $code, $name, $unit, $h->irr, $exempt, $rate]);

        return (int) $pdo->lastInsertId();
    };
    $h->phone = $product('Lite phone', '2330000000011', 'SKU-1', '10.00', 0, 'دستگاه');
    $h->bread = $product('Lite bread', '2330000000035', null, '9.00', 1, 'عدد');
    $h->loose = $product('Lite loose', null, 'SKU-3', null, null, 'Lite box');
    $h->input = fn (array $o = []) => array_merge([
        'currency_id' => (string) $h->irr,
        'pricing_mode' => 'tax_exclusive',
        'issue_date' => '2026-10-01',
        'items' => [
            ['product_id' => (string) $h->phone, 'item_code' => '2330000000011', 'item_name' => 'Lite phone', 'description' => 'Lite phone', 'quantity' => '2', 'unit_price' => '1000000', 'discount_type' => 'percentage', 'discount_value' => '10'],
            ['product_id' => (string) $h->bread, 'item_code' => '', 'item_name' => 'Lite bread', 'description' => 'Lite bread', 'quantity' => '3', 'unit_price' => '50000', 'discount_type' => 'none', 'discount_value' => '0'],
        ],
    ], $o);
    $h->ready = true;
};

$latest = fn (\stdClass $h): array => $h->a->pdo->query("SELECT * FROM invoices WHERE organization_id = {$h->a->orgId} ORDER BY id DESC LIMIT 1")->fetch(\PDO::FETCH_ASSOC);

return [
    'invoice type: optional; type 1 needs a buyer with a national ID or economic code; 2 and 3 do not' => function () use ($setup, $h, $latest) {
        $setup();
        $form = $h->a->request('GET', '/finance/invoices/create')['body'];
        LiteAssert::contains('name="tax_invoice_type"', $form);

        $r = $h->a->request('POST', '/finance/invoices', [], ($h->input)(['contact_id' => (string) $h->anon, 'tax_invoice_type' => '1']));
        LiteAssert::same(422, $r['status']);
        LiteAssert::contains('national ID or economic code', $r['body']);
        $r = $h->a->request('POST', '/finance/invoices', [], ($h->input)(['tax_invoice_type' => '1']));
        LiteAssert::same(422, $r['status'], 'type 1 without any contact');
        $r = $h->a->request('POST', '/finance/invoices', [], ($h->input)(['tax_invoice_type' => '7']));
        LiteAssert::same(422, $r['status']);

        $h->a->request('POST', '/finance/invoices', [], ($h->input)(['contact_id' => (string) $h->anon, 'tax_invoice_type' => '2']));
        LiteAssert::same(2, (int) $latest($h)['tax_invoice_type']);
        $h->a->request('POST', '/finance/invoices', [], ($h->input)(['contact_id' => (string) $h->buyer, 'tax_invoice_type' => '1']));
        $inv = $latest($h);
        LiteAssert::same(1, (int) $inv['tax_invoice_type']);
        $h->type1 = (int) $inv['id'];
        $h->a->request('POST', '/finance/invoices', [], ($h->input)());
        LiteAssert::same(null, $latest($h)['tax_invoice_type'], 'not set: works as before');
    },

    'CSV: only issued; one row per item; VAT from the product on the row after its own discount; Rial' => function () use ($setup, $h, $latest) {
        $setup();
        $draft = $h->a->request('GET', '/finance/invoices/tax-export/download', ['ids' => [(string) $h->type1]])['body'];
        LiteAssert::contains('Not issued', $draft, 'a draft is not exported');
        LiteAssert::notContains('Build the file anyway', $draft);

        $h->a->pdo->exec("UPDATE invoices SET status = 'issued', invoice_number = 'LITE-TAX-1' WHERE id = {$h->type1}"); // as other suites: issuing opens its own transaction
        LiteAssert::same('issued', (string) $h->a->pdo->query("SELECT status FROM invoices WHERE id = {$h->type1}")->fetchColumn());
        LiteAssert::contains('/finance/invoices/tax-export/download?ids[]=' . $h->type1, $h->a->request('GET', '/finance/invoices/' . $h->type1)['body']);
        LiteAssert::contains('Lite Buyer', $h->a->request('GET', '/finance/invoices/tax-export')['body']);

        $body = $h->a->request('GET', '/finance/invoices/tax-export/download', ['ids' => [(string) $h->type1], 'confirm' => '1'])['body'];
        LiteAssert::same("\xEF\xBB\xBF", substr($body, 0, 3), 'UTF-8 BOM');
        $lines = array_map(fn ($l) => str_getcsv($l, ',', '"', ''), array_values(array_filter(explode("\n", substr($body, 3)))));
        $head = array_shift($lines);
        LiteAssert::same(\Modules\Finance\Services\TaxInvoiceExport::COLUMNS, $head);
        LiteAssert::same(2, count($lines));
        [$a, $b] = array_map(fn ($l) => array_combine($head, $l), $lines);
        $number = (string) $h->a->pdo->query("SELECT invoice_number FROM invoices WHERE id = {$h->type1}")->fetchColumn();
        LiteAssert::same([$number, $number, '1', 'cash', '1', 'Lite Buyer', '0012345678', '1234567890'], [$a['crm_invoice_number'], $a['note2'], $a['invoice_type'], $a['payment_type'], $a['buyer_type'], $a['buyer_name'], $a['buyer_national_id'], $a['buyer_postal_code']]);
        LiteAssert::same(['2330000000011', '2', '1631', 'دستگاه', '1000000', '2000000', '200000', '1800000', '10', '180000', '1980000'],
            [$a['product_code'], $a['quantity'], $a['unit_code'], $a['unit'], $a['unit_price'], $a['price_before_discount'], $a['discount'], $a['price_after_discount'], $a['tax_rate'], $a['tax_amount'], $a['total_amount']], 'row 1: 10% after its 10% discount');
        LiteAssert::same(['2330000000035', '1627', '150000', '0', '0', '150000'], [$b['product_code'], $b['unit_code'], $b['price_after_discount'], $b['tax_rate'], $b['tax_amount'], $b['total_amount']], 'row 2: exempt; code from the product');
    },

    'CSV warnings never block: currency, missing ID / unit / VAT %, type not set — shown first, then built on confirm' => function () use ($setup, $h, $latest) {
        $setup();
        $h->a->request('POST', '/finance/invoices', [], ($h->input)([
            'currency_id' => (string) $h->usd,
            'items' => [['product_id' => (string) $h->loose, 'item_code' => 'SKU-3', 'item_name' => 'Lite loose', 'description' => 'Lite loose', 'quantity' => '1', 'unit_price' => '100', 'discount_type' => 'none', 'discount_value' => '0']],
        ]));
        $id = (int) $latest($h)['id'];
        $h->a->pdo->exec("UPDATE invoices SET status = 'issued', invoice_number = 'LITE-TAX-2' WHERE id = {$id}");
        $check = $h->a->request('GET', '/finance/invoices/tax-export/download', ['ids' => [(string) $id, (string) $h->type1]]);
        LiteAssert::same(200, $check['status']);
        foreach (['Currency is USD', 'no 13-digit tax product ID', 'is not in the official list', 'has no VAT %', 'type not set', 'Build the file anyway', 'confirm=1'] as $text) {
            LiteAssert::contains($text, $check['body'], $text);
        }
        LiteAssert::notContains('crm_invoice_number', $check['body'], 'not the file yet');
        $body = $h->a->request('GET', '/finance/invoices/tax-export/download', ['ids' => [(string) $id, (string) $h->type1], 'confirm' => '1'])['body'];
        LiteAssert::same(4, count(array_filter(explode("\n", $body))), 'header + 3 rows');
        LiteAssert::same('notfound', $h->a->outcome('GET', '/finance/invoices/tax-export/download', ['ids' => ['999999999']]), 'unknown / other organization: 404');
    },

    'unit code: official names (Arabic letters too) and codes; anything else is null' => function () {
        LiteAssert::same('1631', \Modules\Finance\Services\TaxInvoiceExport::unitCode('دستگاه'));
        LiteAssert::same('1627', \Modules\Finance\Services\TaxInvoiceExport::unitCode('1627'));
        LiteAssert::same(null, \Modules\Finance\Services\TaxInvoiceExport::unitCode('Lite box'));
        LiteAssert::same(null, \Modules\Finance\Services\TaxInvoiceExport::unitCode(''));
    },

    'per-row VAT from the product: exempt 0, VAT % after the row discount; invoice discount spread pro rata; old way without product rates' => function () use ($setup, $h, $latest) {
        $setup();
        $pdo = $h->a->pdo;
        $eq = fn (string $expected, $actual, string $msg) => LiteAssert::same(0, bccomp($expected, (string) $actual, 6), $msg . ': expected ' . $expected . ', got ' . $actual);
        $inv = $pdo->query("SELECT * FROM invoices WHERE id = {$h->type1}")->fetch(\PDO::FETCH_ASSOC);
        $eq('1950000', $inv['subtotal'], 'subtotal');
        $eq('180000', $inv['tax_total'], 'VAT: 10% of 1,800,000 + exempt bread');
        $eq('2130000', $inv['grand_total'], 'grand total');
        $rows = $pdo->query("SELECT tax_rate, tax_amount FROM invoice_items WHERE invoice_id = {$h->type1} ORDER BY position")->fetchAll(\PDO::FETCH_ASSOC);
        $eq('10', $rows[0]['tax_rate'], 'row 1 rate');
        $eq('180000', $rows[0]['tax_amount'], 'row 1 VAT');
        $eq('0', $rows[1]['tax_rate'], 'row 2 exempt even with a 9% rate set');

        $h->a->request('POST', '/finance/invoices', [], ($h->input)(['invoice_discount' => '195000']));
        $inv = $latest($h);
        $eq('162000', $inv['tax_total'], 'invoice discount 10% spread before VAT');
        $eq('1917000', $inv['grand_total'], '1,950,000 - 195,000 + 162,000');

        $h->a->request('POST', '/finance/invoices', [], ($h->input)(['pricing_mode' => 'tax_inclusive', 'items' => [
            ['product_id' => (string) $h->phone, 'item_name' => 'Lite phone', 'description' => 'Lite phone', 'quantity' => '1', 'unit_price' => '1100000', 'discount_type' => 'none', 'discount_value' => '0'],
        ]]));
        $inv = $latest($h);
        $eq('100000', $inv['tax_total'], 'tax-inclusive: VAT backed out of 1,100,000');
        $eq('1100000', $inv['grand_total'], 'tax-inclusive grand total');

        $h->a->request('POST', '/finance/invoices', [], ($h->input)(['items' => [
            ['product_id' => (string) $h->loose, 'item_name' => 'Lite loose', 'description' => 'Lite loose', 'quantity' => '1', 'unit_price' => '1000', 'discount_type' => 'none', 'discount_value' => '0'],
        ]]));
        $old = $latest($h);
        LiteAssert::same(null, $pdo->query("SELECT tax_rate FROM invoice_items WHERE invoice_id = {$old['id']}")->fetchColumn() ?: null, 'no product rate: the document is taxed as before');

        $h->a->request('POST', '/finance/proformas', [], ($h->input)());
        $pf = $pdo->query("SELECT * FROM proformas WHERE organization_id = {$h->a->orgId} ORDER BY id DESC LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
        $eq('180000', $pf['tax_total'], 'proforma: same per-row VAT');
        $eq('10', $pdo->query("SELECT tax_rate FROM proforma_items WHERE proforma_id = {$pf['id']} ORDER BY position LIMIT 1")->fetchColumn(), 'proforma row rate');
        $h->proforma = (int) $pf['id'];
    },

    'screens and print: row number, code, row discount, VAT % and VAT per row' => function () use ($setup, $h) {
        $setup();
        foreach (['/finance/invoices/' . $h->type1, '/finance/proformas/' . $h->proforma] as $path) {
            $page = $h->a->request('GET', $path)['body'];
            foreach (['<th>#</th>', '>Discount<', '>VAT %<', '2330000000035', '10٪', '200,000'] as $text) {
                LiteAssert::contains($text, $page, $path . ': ' . $text);
            }
        }
        $print = $h->a->request('GET', '/finance/invoices/' . $h->type1 . '/print')['body'];
        LiteAssert::contains('VAT %', $print);
        LiteAssert::contains('180,000', $print);
    },
];
