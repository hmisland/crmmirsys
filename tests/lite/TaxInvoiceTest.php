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
    $h->bread = $product('Lite bread', '2330000000035', null, '0.00', 1, 'عدد');
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
];
