<?php

declare(strict_types=1);

/**
 * Products ← tax product ID (national list, kala.db): the disabled/enabled
 * button, the live search and "frequently used" (per organization), the
 * prefilled product form, the lock on IDs taken from the list, the
 * catalog/manual filter, and that the old product form still works.
 * Uses a tiny SQLite kala.db built in a temp folder (needs pdo_sqlite +
 * FTS5); everything in crm_local runs in one rolled-back transaction.
 */

use Modules\Finance\Services\KalaCatalog;
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
    if (!extension_loaded('pdo_sqlite')) {
        throw new LiteSkip($h->reason = 'pdo_sqlite missing');
    }
    try {
        $h->a = LocalApp::boot();
        $h->a->pdo->query('SELECT tax_product_code FROM products LIMIT 1');
        $h->a->pdo->query('SELECT 1 FROM product_tax_favorites LIMIT 1');
    } catch (\Throwable $e) {
        throw new LiteSkip($h->reason = 'local database unavailable or finance 0025/0026 not migrated: ' . $e->getMessage());
    }
    $h->a->locale('en');

    $dir = sys_get_temp_dir() . '/kala-lite-' . getmypid();
    @mkdir($dir);
    $h->db = $dir . '/kala.db';
    @unlink($h->db);
    $s = new \PDO('sqlite:' . $h->db);
    $s->exec('CREATE TABLE kala(id TEXT PRIMARY KEY, norm TEXT, vat INTEGER, taxable TEXT, type TEXT)');
    $ins = $s->prepare('INSERT INTO kala VALUES (?,?,?,?,?)');
    foreach ([
        ['2330000000011', KalaCatalog::normalize('گوشی تلفن همراه سامسونگ'), 10, 'مشمول', 'کالا'],
        ['2330000000028', KalaCatalog::normalize('گوشي تلفن ثابت'), 10, 'مشمول', 'کالا'],
        ['2330000000035', KalaCatalog::normalize('نان سنگک'), 0, 'معاف', 'کالا'],
    ] as $row) {
        $ins->execute($row);
    }
    $s->exec("CREATE VIRTUAL TABLE kala_fts USING fts5(norm, content='kala', content_rowid='rowid', tokenize='unicode61 remove_diacritics 2')");
    $s->exec('INSERT INTO kala_fts(rowid, norm) SELECT rowid, norm FROM kala');
    $s = null;

    $h->kala = $h->a->container()->make(KalaCatalog::class);
    $h->point = function (string $path) use ($h): void {
        foreach (['path' => $path, 'available' => null, 'pdo' => null] as $p => $v) {
            (new \ReflectionProperty(KalaCatalog::class, $p))->setValue($h->kala, $v);
        }
    };
    $h->currency = (int) $h->a->pdo->query('SELECT id FROM currencies ORDER BY id LIMIT 1')->fetchColumn();
    $h->ready = true;
};

$base = fn (array $more = []) => $more + ['name' => 'Lite tax product', 'price' => '100', 'is_active' => '1'];

return [
    'no kala.db: the button is shown disabled with the "send a ticket" hint; the catalog pages are 404' => function () use ($setup, $h) {
        $setup();
        ($h->point)($h->db . '.missing');
        $body = $h->a->request('GET', '/finance/products')['body'];
        LiteAssert::contains('New product from tax product ID', $body);
        LiteAssert::contains('disabled', $body);
        LiteAssert::contains('send a ticket to the administrator', $body);
        LiteAssert::contains('/org/tickets/create', $body);
        LiteAssert::same('notfound', $h->a->outcome('GET', '/finance/products/tax-catalog'));
        LiteAssert::same('notfound', $h->a->outcome('GET', '/finance/products/tax-catalog/search', ['q' => 'نان']));
    },

    'a kala.db still being built counts as missing' => function () use ($setup, $h) {
        $setup();
        touch($h->db . '.building');
        ($h->point)($h->db);
        LiteAssert::false($h->kala->available());
        unlink($h->db . '.building');
        ($h->point)($h->db);
        LiteAssert::true($h->kala->available());
    },

    'with kala.db: the button links to the catalog page; search is prefix, normalized, and by 13-digit ID' => function () use ($setup, $h) {
        $setup();
        ($h->point)($h->db);
        $body = $h->a->request('GET', '/finance/products')['body'];
        LiteAssert::contains('href="/finance/products/tax-catalog"', $body);
        LiteAssert::notContains('send a ticket to the administrator', $body);
        $page = $h->a->request('GET', '/finance/products/tax-catalog')['body'];
        LiteAssert::contains('My products', $page);
        LiteAssert::contains('Frequently used', $page);
        $found = fn (string $q) => (function (array $ids) { sort($ids); return $ids; })(array_column(json_decode($h->a->request('GET', '/finance/products/tax-catalog/search', ['q' => $q])['body'], true)['items'], 'id'));
        LiteAssert::same(['2330000000011', '2330000000028'], $found('تلف'), 'prefix match, Arabic ي/ك normalized');
        LiteAssert::same(['2330000000011'], $found('گوشي سامسو'), 'every word must match');
        LiteAssert::same(['2330000000035'], $found('۲۳۳۰۰۰۰۰۰۰۰۳۵'), 'Persian-digit ID lookup');
        LiteAssert::same([], $found('ن'), 'one letter: nothing');
    },

    'star → frequently used (per organization, leaves the results); green star → back' => function () use ($setup, $h) {
        $setup();
        ($h->point)($h->db);
        $r = $h->a->request('POST', '/finance/products/tax-catalog/favorites', [], ['code' => '2330000000035']);
        LiteAssert::same(true, json_decode($r['body'], true)['ok']);
        LiteAssert::same(1, (int) $h->a->pdo->query("SELECT COUNT(*) FROM product_tax_favorites WHERE organization_id = {$h->a->orgId} AND tax_product_code = '2330000000035'")->fetchColumn());
        $h->a->request('POST', '/finance/products/tax-catalog/favorites', [], ['code' => '2330000000035']);
        LiteAssert::same(1, (int) $h->a->pdo->query("SELECT COUNT(*) FROM product_tax_favorites WHERE tax_product_code = '2330000000035'")->fetchColumn(), 'no duplicate');
        $items = json_decode($h->a->request('GET', '/finance/products/tax-catalog/search', ['q' => 'نان'])['body'], true)['items'];
        LiteAssert::same([], $items, 'a favorite is not repeated in the results');
        $page = $h->a->request('GET', '/finance/products/tax-catalog')['body'];
        LiteAssert::contains('tax-star--on', $page);
        LiteAssert::contains('/finance/products/create?tax_code=2330000000035', $page);
        LiteAssert::same('status:404', $h->a->outcome('POST', '/finance/products/tax-catalog/favorites', [], ['code' => '9999999999999']), 'only IDs in the list');
        $h->a->request('POST', '/finance/products/tax-catalog/favorites/remove', [], ['code' => '2330000000035']);
        LiteAssert::same(0, (int) $h->a->pdo->query("SELECT COUNT(*) FROM product_tax_favorites WHERE tax_product_code = '2330000000035'")->fetchColumn());
    },

    '"+" opens the product form prefilled; saving creates a locked catalog product and drops the favorite' => function () use ($setup, $h, $base) {
        $setup();
        ($h->point)($h->db);
        $h->a->request('POST', '/finance/products/tax-catalog/favorites', [], ['code' => '2330000000011']);
        $form = $h->a->request('GET', '/finance/products/create', ['tax_code' => '2330000000011'])['body'];
        LiteAssert::contains('value="2330000000011"', $form);
        LiteAssert::contains('name="tax_from_catalog"', $form);
        LiteAssert::contains(e_lite(KalaCatalog::normalize('گوشی تلفن همراه سامسونگ')), $form);

        $r = $h->a->request('POST', '/finance/products', [], $base(['name' => 'Lite phone', 'currency_id' => (string) $h->currency, 'tax_product_code' => '2330000000011', 'tax_from_catalog' => '1', 'vat_exempt' => '0', 'vat_rate' => '9']));
        LiteAssert::true($r['status'] < 400, 'saved');
        $p = $h->a->pdo->query("SELECT * FROM products WHERE name = 'Lite phone' AND organization_id = {$h->a->orgId}")->fetch(\PDO::FETCH_ASSOC);
        LiteAssert::same(['2330000000011', 'catalog', 0, '9.00'], [$p['tax_product_code'], $p['tax_product_source'], (int) $p['vat_exempt'], (string) $p['vat_rate']], 'VAT % is the user\'s value');
        LiteAssert::same(0, (int) $h->a->pdo->query("SELECT COUNT(*) FROM product_tax_favorites WHERE tax_product_code = '2330000000011'")->fetchColumn(), 'left frequently used');
        $h->catalogId = (int) $p['id'];

        $edit = $h->a->request('GET', '/finance/products/' . $h->catalogId . '/edit')['body'];
        LiteAssert::contains('readonly', $edit);
        LiteAssert::contains('it cannot be changed', $edit);
        $h->a->request('POST', '/finance/products/' . $h->catalogId, [], $base(['name' => 'Lite phone', 'currency_id' => (string) $h->currency, 'tax_product_code' => '2330000000028', 'vat_rate' => '10']));
        $p = $h->a->pdo->query("SELECT tax_product_code, tax_product_source, vat_rate FROM products WHERE id = {$h->catalogId}")->fetch(\PDO::FETCH_ASSOC);
        LiteAssert::same(['2330000000011', 'catalog', '10.00'], array_values(array_map('strval', $p)), 'ID locked, VAT % editable');
    },

    'manual ID: editable, validated; a forged tax_from_catalog without the list stays manual' => function () use ($setup, $h, $base) {
        $setup();
        ($h->point)($h->db);
        $r = $h->a->request('POST', '/finance/products', [], $base(['name' => 'Lite manual', 'currency_id' => (string) $h->currency, 'tax_product_code' => '12345']));
        LiteAssert::same(422, $r['status']);
        LiteAssert::contains('13 digits', $r['body']);
        $r = $h->a->request('POST', '/finance/products', [], $base(['name' => 'Lite manual', 'currency_id' => (string) $h->currency, 'vat_rate' => '150']));
        LiteAssert::same(422, $r['status']);
        $h->a->request('POST', '/finance/products', [], $base(['name' => 'Lite manual', 'currency_id' => (string) $h->currency, 'tax_product_code' => '1111111111111', 'tax_from_catalog' => '1']));
        $p = $h->a->pdo->query("SELECT * FROM products WHERE name = 'Lite manual' AND organization_id = {$h->a->orgId}")->fetch(\PDO::FETCH_ASSOC);
        LiteAssert::same(['1111111111111', 'manual'], [$p['tax_product_code'], $p['tax_product_source']]);
        $h->manualId = (int) $p['id'];
        $h->a->request('POST', '/finance/products/' . $h->manualId, [], $base(['name' => 'Lite manual', 'currency_id' => (string) $h->currency, 'tax_product_code' => '2222222222222']));
        LiteAssert::same('2222222222222', (string) $h->a->pdo->query("SELECT tax_product_code FROM products WHERE id = {$h->manualId}")->fetchColumn());
    },

    'the old product form still saves without any tax field, and the filter splits catalog / manual' => function () use ($setup, $h, $base) {
        $setup();
        ($h->point)($h->db);
        $r = $h->a->request('POST', '/finance/products', [], $base(['name' => 'Lite plain', 'currency_id' => (string) $h->currency]));
        LiteAssert::true($r['status'] < 400);
        $p = $h->a->pdo->query("SELECT tax_product_code, tax_product_source, vat_exempt, vat_rate FROM products WHERE name = 'Lite plain' AND organization_id = {$h->a->orgId}")->fetch(\PDO::FETCH_ASSOC);
        LiteAssert::same([null, null, null, null], array_values($p));
        $catalog = $h->a->request('GET', '/finance/products', ['tax_source' => 'catalog', 'q' => 'Lite'])['body'];
        LiteAssert::contains('Lite phone', $catalog);
        LiteAssert::notContains('Lite manual', $catalog);
        LiteAssert::notContains('Lite plain', $catalog);
        $manual = $h->a->request('GET', '/finance/products', ['tax_source' => 'manual', 'q' => 'Lite'])['body'];
        LiteAssert::notContains('Lite phone', $manual);
        LiteAssert::contains('Lite manual', $manual);
        LiteAssert::contains('Lite plain', $manual);
    },

    'every new key exists in all five languages' => function () {
        $root = dirname(__DIR__, 2) . '/lang/';
        $en = require $root . 'en.php';
        $keys = array_values(array_filter(array_keys($en), fn ($k) => preg_match('/^products\.(tax_|vat_|error\.(tax_code|vat_rate))/', $k) === 1));
        LiteAssert::true(count($keys) >= 33);
        foreach (['fa', 'ar', 'tr', 'fr'] as $lang) {
            $lines = require $root . $lang . '.php';
            LiteAssert::same([], array_values(array_diff($keys, array_keys($lines))), "{$lang} has every key");
        }
    },

    'rollback leaves no test rows behind' => function () use ($setup, $h) {
        $setup();
        $h->a->rollBack();
        @unlink($h->db);
        LiteAssert::same(0, (int) $h->a->pdo->query("SELECT COUNT(*) FROM products WHERE name LIKE 'Lite %'")->fetchColumn());
    },
];

function e_lite(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
