<?php

declare(strict_types=1);

/**
 * Phase C — WooCommerce purchase conditions in Marketing's dynamic
 * segments: each condition, shop scope, excluded statuses, combinations,
 * validation, snapshots, removed shops and organization isolation.
 * crm_local only, one transaction, rolled back. No store is called.
 *
 * Tests run in order and share fixtures.
 */

use App\Infrastructure\Repositories\OrganizationContext;
use Modules\Marketing\Segments\SegmentConditionRegistry;
use Modules\Marketing\Segments\SegmentRules;
use Modules\Marketing\Segments\SqlParams;
use Modules\Marketing\Services\SegmentService;
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
        $h->a->pdo->query('SELECT 1 FROM woo_orders LIMIT 1');
    } catch (\Throwable $e) {
        $h->reason = 'local database unavailable or WooCommerce not migrated: ' . $e->getMessage();
        throw new LiteSkip($h->reason);
    }
    $a = $h->a;
    $a->locale('en');
    $pdo = $a->pdo;
    $pdo->exec("DELETE FROM modules WHERE `key` IN ('marketing', 'woocommerce')");
    foreach (['marketing', 'woocommerce'] as $key) {
        $pdo->prepare("INSERT INTO modules (`key`, name, version, type, is_core, status, dependencies) VALUES (?, ?, '1.0.0', 'addon', 0, 'active', '[]')")->execute([$key, $key]);
        $pdo->prepare('INSERT INTO organization_modules (organization_id, module_id, enabled) VALUES (?, ?, 1)')->execute([$a->orgId, (int) $pdo->lastInsertId()]);
    }

    $shop = function (int $org, string $name) use ($pdo): int {
        $pdo->prepare("INSERT INTO integration_connections (organization_id, provider, name, enabled, status, config_json, webhook_key) VALUES (?, 'woocommerce', ?, 1, 'connected', '{}', ?)")
            ->execute([$org, $name, bin2hex(random_bytes(20))]);

        return (int) $pdo->lastInsertId();
    };
    $h->shopA = $shop($a->orgId, 'Lite Seg Shop A');
    $h->shopB = $shop($a->orgId, 'Lite Seg Shop B');

    $product = function (int $org, int $shopId, int $ext, string $name) use ($pdo): int {
        $pdo->prepare('INSERT INTO woo_products (organization_id, connection_id, external_id, name) VALUES (?, ?, ?, ?)')->execute([$org, $shopId, $ext, $name]);

        return (int) $pdo->lastInsertId();
    };
    $h->p1 = $product($a->orgId, $h->shopA, 501, 'Lite Seg Mug');
    $h->p2 = $product($a->orgId, $h->shopB, 502, 'Lite Seg Shirt');

    $contact = function (int $org, string $name) use ($pdo): int {
        $pdo->prepare("INSERT INTO contacts (organization_id, first_name, last_name, status) VALUES (?, ?, 'LiteSeg', 'active')")->execute([$org, $name]);

        return (int) $pdo->lastInsertId();
    };
    $customer = function (int $org, int $shopId, int $contactId, string $key) use ($pdo): int {
        $pdo->prepare('INSERT INTO woo_customers (organization_id, connection_id, external_key, contact_id, contact_synced_at) VALUES (?, ?, ?, ?, NOW())')->execute([$org, $shopId, $key, $contactId]);

        return (int) $pdo->lastInsertId();
    };
    $next = 9000;
    $order = function (int $org, int $shopId, int $customerId, string $status, string $total, int $daysAgo, ?int $productId = null) use ($pdo, &$next): void {
        $ext = ++$next;
        $pdo->prepare('INSERT INTO woo_orders (organization_id, connection_id, external_id, order_number, customer_id, status, currency, total, external_created_at) VALUES (?, ?, ?, ?, ?, ?, \'IRR\', ?, ?)')
            ->execute([$org, $shopId, $ext, (string) $ext, $customerId, $status, $total, gmdate('Y-m-d H:i:s', time() - $daysAgo * 86400)]);
        $orderId = (int) $pdo->lastInsertId();
        if ($productId !== null) {
            $pdo->prepare('INSERT INTO woo_order_items (organization_id, order_id, external_id, product_id, name, quantity, total) VALUES (?, ?, ?, ?, \'item\', 1, ?)')->execute([$org, $orderId, $ext * 10, $productId, $total]);
        }
    };

    $c = [];
    foreach (['Alice', 'Bob', 'Carol', 'Dave', 'Erin'] as $name) {
        $c[$name] = $contact($a->orgId, $name);
    }
    // Alice — shop A: two completed purchases (40 and 5 days ago), the mug.
    $alice = $customer($a->orgId, $h->shopA, $c['Alice'], 'id:1');
    $order($a->orgId, $h->shopA, $alice, 'completed', '100', 40, $h->p1);
    $order($a->orgId, $h->shopA, $alice, 'completed', '200', 5);
    // Bob — shop B: one processing purchase (2 days ago, the shirt); shop A: a cancelled order (never counted).
    $order($a->orgId, $h->shopB, $customer($a->orgId, $h->shopB, $c['Bob'], 'id:2'), 'processing', '500', 2, $h->p2);
    $order($a->orgId, $h->shopA, $customer($a->orgId, $h->shopA, $c['Bob'], 'id:3'), 'cancelled', '1000', 1, $h->p1);
    // Carol — shop A: refunded only (never counted).
    $order($a->orgId, $h->shopA, $customer($a->orgId, $h->shopA, $c['Carol'], 'id:4'), 'refunded', '300', 3);
    // Dave — no orders. Erin — both shops: A (10 days ago) and B (1 day ago).
    $order($a->orgId, $h->shopA, $customer($a->orgId, $h->shopA, $c['Erin'], 'id:5'), 'completed', '50', 10);
    $order($a->orgId, $h->shopB, $customer($a->orgId, $h->shopB, $c['Erin'], 'id:6'), 'completed', '70', 1);

    // Another organization's shop whose (corrupt) customer row points at OUR contact Dave: its orders must never count.
    if ($a->otherOrgId !== null) {
        $h->foreignShop = $shop($a->otherOrgId, 'Foreign shop');
        $order($a->otherOrgId, $h->foreignShop, $customer($a->otherOrgId, $h->foreignShop, $c['Dave'], 'id:9'), 'completed', '99999', 1);
    }
    $h->c = $c;
    $h->registry = $a->container()->make(SegmentConditionRegistry::class);
    $h->registry->reset();
    $h->rules = new SegmentRules($h->registry);
    $h->ready = true;
};

/** Names of the test contacts matching one condition (validated like a saved segment). */
$match = function (array $conditions, string $groupMatch = 'all', ?string $asOf = null) use ($h): array {
    $groups = isset($conditions[0][0]) ? $conditions : [$conditions];
    $result = $h->rules->validate(['match' => $groupMatch, 'groups' => array_map(fn ($g) => ['match' => 'all', 'conditions' => $g], $groups)]);
    if ($result['rules'] === null) {
        throw new \RuntimeException('invalid rules: ' . implode(', ', $result['errors']));
    }
    $params = new SqlParams($h->a->orgId, 'tst', $asOf);
    $sql = $h->rules->compile($result['rules'], $params);
    $ids = implode(',', array_map('intval', $h->c));
    $stmt = $h->a->pdo->prepare("SELECT c.id FROM contacts c WHERE c.organization_id = {$h->a->orgId} AND c.id IN ({$ids}) AND {$sql}");
    $stmt->execute($params->all());
    $found = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    $names = array_keys(array_filter($h->c, fn ($id) => in_array($id, $found, true)));
    sort($names);

    return $names;
};
$cond = fn (string $field, string $op, mixed $value = null, ?string $scope = null) => array_filter(['field' => $field, 'op' => $op, 'value' => $value, 'scope' => $scope], fn ($v) => $v !== null) + ['field' => $field];

return [
    'registered: purchase conditions in the builder, with shop scopes; no product-category condition (data not available)' => function () use ($setup, $h) {
        $setup();
        $schema = $h->a->container()->make(SegmentService::class)->builderSchema($h->a->admin);
        $byKey = array_column($schema, null, 'key');
        foreach (['woo_purchased', 'woo_order_count', 'woo_total_spent', 'woo_product', 'woo_order_status', 'woo_last_order', 'woo_first_order'] as $key) {
            LiteAssert::true(isset($byKey[$key]), $key);
        }
        LiteAssert::same(['all', (string) $h->shopA, (string) $h->shopB], array_map('strval', array_keys($byKey['woo_order_count']['scopes'])));
        LiteAssert::same([], $byKey['woo_product']['scopes'], 'a product already belongs to one shop');
        LiteAssert::true(isset($byKey['woo_product']['choices'][(string) $h->p1]));
        LiteAssert::false(isset($byKey['woo_product_category']));
    },

    'has purchased: completed / processing only; cancelled and refunded never count' => function () use ($setup, $match, $cond) {
        $setup();
        LiteAssert::same(['Alice', 'Bob', 'Erin'], $match([$cond('woo_purchased', 'yes')]));
        LiteAssert::same(['Carol', 'Dave'], $match([$cond('woo_purchased', 'no')]));
    },

    'shop scope: a contact of several shops matches per selected shop' => function () use ($setup, $h, $match, $cond) {
        $setup();
        LiteAssert::same(['Alice', 'Erin'], $match([$cond('woo_purchased', 'yes', null, (string) $h->shopA)]));
        LiteAssert::same(['Bob', 'Erin'], $match([$cond('woo_purchased', 'yes', null, (string) $h->shopB)]));
    },

    'number of purchases: across shops or per shop' => function () use ($setup, $h, $match, $cond) {
        $setup();
        LiteAssert::same(['Alice', 'Erin'], $match([$cond('woo_order_count', 'gte', '2')]));
        LiteAssert::same(['Alice'], $match([$cond('woo_order_count', 'gte', '2', (string) $h->shopA)]));
        LiteAssert::same(['Bob', 'Erin'], $match([$cond('woo_order_count', 'eq_number', '1', (string) $h->shopB)]));
    },

    'total purchase amount: excluded statuses add nothing; a contact without purchases totals 0' => function () use ($setup, $h, $match, $cond) {
        $setup();
        LiteAssert::same(['Alice', 'Bob'], $match([$cond('woo_total_spent', 'gte', '250')]));
        LiteAssert::false(in_array('Bob', $match([$cond('woo_total_spent', 'gte', '600')]), true), 'the cancelled 1000 is not counted');
        LiteAssert::same(['Carol', 'Dave'], $match([$cond('woo_total_spent', 'lte', '10')]));
        LiteAssert::same(['Erin'], $match([$cond('woo_total_spent', 'gte', '70', (string) $h->shopB), $cond('woo_total_spent', 'lte', '100', (string) $h->shopB)]));
    },

    'purchased product and order status' => function () use ($setup, $h, $match, $cond) {
        $setup();
        LiteAssert::same(['Alice'], $match([$cond('woo_product', 'has', (string) $h->p1)]), 'Bob\'s mug was in a cancelled order');
        LiteAssert::same(['Bob'], $match([$cond('woo_product', 'has', (string) $h->p2)]));
        LiteAssert::same(['Carol'], $match([$cond('woo_order_status', 'has', 'refunded')]));
        LiteAssert::same(['Bob'], $match([$cond('woo_order_status', 'has', 'cancelled', (string) $h->shopA)]));
        LiteAssert::same([], $match([$cond('woo_order_status', 'has', 'cancelled', (string) $h->shopB)]));
    },

    'recency: last and first purchase (relative days, calendar dates, per shop)' => function () use ($setup, $h, $match, $cond) {
        $setup();
        LiteAssert::same(['Alice', 'Bob', 'Erin'], $match([$cond('woo_last_order', 'last_days', '7')]));
        LiteAssert::same([], $match([$cond('woo_last_order', 'last_days', '3', (string) $h->shopA)]));
        LiteAssert::same(['Bob', 'Erin'], $match([$cond('woo_last_order', 'last_days', '3', (string) $h->shopB)]));
        LiteAssert::same(['Alice'], $match([$cond('woo_first_order', 'older_than_days', '30')]));
        LiteAssert::same(['Alice'], $match([$cond('woo_first_order', 'before', gmdate('Y-m-d', time() - 20 * 86400))]));
        LiteAssert::same([], $match([$cond('woo_last_order', 'older_than_days', '30')]), 'no purchase → no "last purchase" at all');
    },

    'combinations: (purchased AND total >= 250) OR product shirt; groups AND' => function () use ($setup, $h, $match, $cond) {
        $setup();
        LiteAssert::same(['Alice', 'Bob'], $match([[$cond('woo_purchased', 'yes'), $cond('woo_total_spent', 'gte', '250')], [$cond('woo_product', 'has', (string) $h->p2)]], 'any'));
        LiteAssert::same(['Erin'], $match([[$cond('woo_purchased', 'yes', null, (string) $h->shopA)], [$cond('woo_purchased', 'yes', null, (string) $h->shopB)]], 'all'));
    },

    'validation: unknown scope (e.g. another organization\'s shop) and bad amounts refused; scope defaults to all' => function () use ($setup, $h) {
        $setup();
        $check = fn (array $c) => $h->rules->validate(['groups' => [['conditions' => [$c]]]]);
        if (isset($h->foreignShop)) {
            LiteAssert::true(in_array('marketing.segment.error.scope', $check(['field' => 'woo_purchased', 'op' => 'yes', 'scope' => (string) $h->foreignShop])['errors'], true));
        }
        LiteAssert::true(in_array('marketing.segment.error.value', $check(['field' => 'woo_total_spent', 'op' => 'gte', 'value' => '-5'])['errors'], true));
        LiteAssert::true(in_array('marketing.segment.error.value', $check(['field' => 'woo_total_spent', 'op' => 'gte', 'value' => '1e9'])['errors'], true));
        LiteAssert::same('all', $check(['field' => 'woo_purchased', 'op' => 'yes'])['rules']['groups'][0]['conditions'][0]['scope']);
        LiteAssert::same('1250000.5', $check(['field' => 'woo_total_spent', 'op' => 'gte', 'value' => '1,250,000.5'])['rules']['groups'][0]['conditions'][0]['value']);
    },

    'stored rules: a removed shop matches nothing; a tampered scope never reaches SQL' => function () use ($setup, $h) {
        $setup();
        $compile = function (array $c) use ($h): array {
            $params = new SqlParams($h->a->orgId, 'tmp');
            $sql = $h->rules->compile(['match' => 'all', 'groups' => [['match' => 'all', 'conditions' => [$c]]]], $params);
            $stmt = $h->a->pdo->prepare("SELECT COUNT(*) FROM contacts c WHERE c.organization_id = {$h->a->orgId} AND {$sql}");
            $stmt->execute($params->all());

            return [$sql, (int) $stmt->fetchColumn()];
        };
        LiteAssert::same(0, $compile(['field' => 'woo_purchased', 'op' => 'yes', 'scope' => '987654'])[1]);
        LiteAssert::same('1 = 0', $compile(['field' => 'woo_purchased', 'op' => 'yes', 'scope' => "1) OR (1=1"])[0]);
    },

    'organization isolation: another organization\'s orders never count, even linked to our contact and "in all shops"' => function () use ($setup, $h, $match, $cond) {
        $setup();
        if (!isset($h->foreignShop)) {
            throw new LiteSkip('needs a second organization');
        }
        LiteAssert::false(in_array('Dave', $match([$cond('woo_total_spent', 'gte', '1000')]), true));
        LiteAssert::false(in_array('Dave', $match([$cond('woo_purchased', 'yes')]), true));
        LiteAssert::false(in_array('Dave', $match([$cond('woo_last_order', 'last_days', '7')]), true));
        LiteAssert::false(in_array('Dave', $match([$cond('woo_order_status', 'has', 'completed')]), true));
    },

    'campaign path: a saved segment resolves through the engine; the approval snapshot keeps the scope' => function () use ($setup, $h) {
        $setup();
        $service = $h->a->container()->make(SegmentService::class);
        $id = $service->create($h->a->admin, ['type' => 'dynamic', 'name' => 'Lite Seg buyers B', 'rules_json' => json_encode(['groups' => [['conditions' => [['field' => 'woo_purchased', 'op' => 'yes', 'scope' => (string) $h->shopB]]]]])]);
        $stored = json_decode((string) $h->a->pdo->query("SELECT rules FROM marketing_segments WHERE id = {$id}")->fetchColumn(), true);
        LiteAssert::same((string) $h->shopB, $stored['groups'][0]['conditions'][0]['scope']);
        $preview = $service->preview($h->a->admin, $id, null);
        LiteAssert::true($preview['total'] >= 2);
        $names = array_map(fn ($r) => $r['first_name'] ?? '', $preview['rows']);
        LiteAssert::true(in_array('Bob', $names, true) && in_array('Erin', $names, true) && !in_array('Alice', $names, true));

        $audience = new \Modules\Marketing\Segments\SegmentAudience(new \Modules\Marketing\SegmentRepository(), $h->rules);
        $snapshot = $audience->snapshot($id);
        LiteAssert::same((string) $h->shopB, $snapshot['rules']['groups'][0]['conditions'][0]['scope']);

        $h->a->as($h->a->admin);
        $body = $h->a->request('GET', '/marketing/segments/' . $id)['body'];
        LiteAssert::contains('Lite Seg Shop B', $body);
        LiteAssert::notContains('woocommerce.segment.', $body);
    },

    'rollback leaves no test rows behind' => function () use ($setup, $h) {
        $setup();
        $h->a->rollBack();
        LiteAssert::same(0, (int) $h->a->pdo->query("SELECT COUNT(*) FROM contacts WHERE last_name = 'LiteSeg'")->fetchColumn());
    },
];
