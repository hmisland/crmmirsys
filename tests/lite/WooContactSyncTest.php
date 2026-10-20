<?php

declare(strict_types=1);

/**
 * Phase A — WooCommerce customer → CRM contact sync, end to end through
 * the real container, importer, router and database (crm_local only,
 * one transaction, rolled back). No store is called: orders are fed to
 * the importer directly, as the worker does with a verified webhook.
 *
 * Tests run in order and share fixtures.
 */

use App\Infrastructure\Http\HttpClientInterface;
use App\Infrastructure\Http\HttpResponse;
use App\Infrastructure\Integrations\IntegrationRegistry;
use App\Infrastructure\Integrations\IntegrationService;
use App\Infrastructure\Modules\OrganizationEntitlements;
use App\Infrastructure\Repositories\OrganizationContext;
use Modules\WooCommerce\ContactSync\ContactFieldMapper;
use Modules\WooCommerce\ContactSync\ContactSyncSettingsService;
use Modules\WooCommerce\ContactSync\CustomerContactSync;
use Modules\WooCommerce\Api\WooCommerceClient;
use Modules\WooCommerce\Services\WooOrderImporter;
use Modules\WooCommerce\WooCommerceProvider;
use Tests\Lite\Support\LocalApp;

require_once __DIR__ . '/Support/LocalApp.php';

/** The shop's REST API, faked: returns the queued order lists; never calls a real store. */
final class FakeContactSyncShopHttp implements HttpClientInterface
{
    /** @var array<int, array> order lists to return, one per request */
    public array $pages = [];
    public int $calls = 0;

    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeoutSeconds = 15): HttpResponse
    {
        $this->calls++;

        return new HttpResponse(200, (string) json_encode(array_shift($this->pages) ?? []), ['x-wp-totalpages' => '1']);
    }
}

$h = new \stdClass();
$h->ready = false;
$h->reason = '';
$h->next = 7000;

$setup = function () use ($h): void {
    if ($h->ready) {
        return;
    }
    if ($h->reason !== '') {
        throw new LiteSkip($h->reason);
    }
    try {
        $h->a = LocalApp::boot();
        $h->a->pdo->query('SELECT contact_sync_status FROM woo_customers LIMIT 1');
    } catch (\Throwable $e) {
        $h->reason = 'local database unavailable or WooCommerce 0002 not migrated: ' . $e->getMessage();
        throw new LiteSkip($h->reason);
    }
    $a = $h->a;
    $a->locale('en');
    $pdo = $a->pdo;
    $c = $a->container();
    $pdo->exec("DELETE FROM modules WHERE `key` = 'woocommerce'");
    $pdo->exec("INSERT INTO modules (`key`, name, version, type, is_core, status, dependencies) VALUES ('woocommerce', 'WooCommerce', '1.0.0', 'addon', 0, 'active', '[]')");
    $h->moduleId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO organization_modules (organization_id, module_id, enabled) VALUES (?, ?, 1)')->execute([$a->orgId, $h->moduleId]);
    $c->make(OrganizationEntitlements::class)->reset();

    $created = $c->make(IntegrationService::class)->create($a->admin, 'woocommerce', [
        'name' => 'Lite shop', 'store_url' => 'https://www.lite-shop.example.com/', 'consumer_key' => 'ck_' . str_repeat('a1', 20), 'consumer_secret' => 'cs_' . str_repeat('b2', 20),
    ]);
    if ($created['errors'] !== []) {
        throw new \RuntimeException('connection not created: ' . json_encode($created['errors']));
    }
    $h->connId = (int) $created['id'];
    $pdo->prepare('INSERT INTO contact_categories (organization_id, name) VALUES (?, ?)')->execute([$a->orgId, 'Lite shop customers']);
    $h->categoryId = (int) $pdo->lastInsertId();

    $h->importer = $c->make(WooOrderImporter::class);
    // The shop's API is always faked in this suite (the "Sync contacts now" button fetches orders first).
    $h->http = new FakeContactSyncShopHttp();
    $c->make(IntegrationRegistry::class)->register(new WooCommerceProvider(new WooCommerceClient($h->http), $h->importer, ['per_page' => 50, 'pages_per_job' => 1, 'initial_days' => 30]));
    $h->sync = $c->make(CustomerContactSync::class);
    $h->ready = true;
};

/** A WooCommerce order JSON object (shape of /wc/v3/orders) with the given billing details. */
$order = function (array $billing, array $extra = []) use ($h): array {
    $id = ++$h->next;

    return array_merge([
        'id' => $id,
        'number' => (string) $id,
        'status' => 'processing',
        'currency' => 'IRR',
        'total' => '1250000.00',
        'customer_id' => 0,
        'date_created_gmt' => gmdate('Y-m-d\TH:i:s', time() - 600),
        'date_modified_gmt' => gmdate('Y-m-d\TH:i:s', time() - 600 + $id % 100),
        'billing' => $billing,
        'line_items' => [['id' => $id * 10, 'product_id' => 77, 'variation_id' => 0, 'name' => 'Lite product', 'sku' => 'LITE-77', 'quantity' => 1, 'total' => '1250000.00']],
    ], $extra);
};

$customer = fn (string $email) => (function () use ($h, $email): ?array {
    $stmt = $h->a->pdo->prepare('SELECT * FROM woo_customers WHERE organization_id = ? AND connection_id = ? AND email = ?');
    $stmt->execute([$h->a->orgId, $h->connId, $email]);

    return $stmt->fetch() ?: null;
})();

$contact = fn (int $id) => $h->a->pdo->query('SELECT * FROM contacts WHERE id = ' . $id)->fetch() ?: null;
$count = fn (string $sql) => (int) $h->a->pdo->query($sql)->fetchColumn();

return [
    // --- Field mapping (no database) -----------------------------------------------

    'mapping: billing fields, address lines joined, province from the WooCommerce state code, country name, postal digits' => function () {
        $v = ContactFieldMapper::map([
            'first_name' => '  Sara ', 'last_name' => 'Ahmadi', 'address_1' => 'Valiasr St.', 'address_2' => 'No. 12', 'city' => 'Tehran',
            'state' => 'THR', 'postcode' => '۱۲۳۴۵-۶۷۸۹۰', 'country' => 'IR', 'email' => 'Sara@Example.TEST', 'phone' => '+98 912 000 1111',
        ], ContactFieldMapper::defaults(), 'en');
        LiteAssert::same('Sara', $v['first_name']);
        LiteAssert::same('Valiasr St.، No. 12', $v['address']);
        LiteAssert::same('تهران', $v['province']);
        LiteAssert::same('12345-67890', $v['postal_code']);
        LiteAssert::same(class_exists(\Locale::class) ? 'Iran' : 'IR', $v['country']);
        LiteAssert::same('sara@example.test', $v['email']);
        LiteAssert::same('09120001111', $v['mobile']);
        LiteAssert::same(null, $v['landline']);
        // A Persian province name is accepted; an unknown code / a foreign state is not invented.
        LiteAssert::same('اصفهان', ContactFieldMapper::map(['state' => 'اصفهان', 'country' => 'IR'], ContactFieldMapper::defaults())['province']);
        LiteAssert::same(null, ContactFieldMapper::map(['state' => 'XYZ', 'country' => 'IR'], ContactFieldMapper::defaults())['province']);
        LiteAssert::same(null, ContactFieldMapper::map(['state' => 'CA', 'country' => 'US'], ContactFieldMapper::defaults())['province']);
    },

    'mapping: "do not import", address line 1 only, unknown mapping values fall back to defaults' => function () {
        $mapping = ['address' => 'address_1', 'city' => '', 'country' => '', 'email' => 'nonsense', 'evil' => 'x'];
        $v = ContactFieldMapper::map(['address_1' => 'A', 'address_2' => 'B', 'city' => 'Shiraz', 'country' => 'IR', 'email' => 'x@example.test'], $mapping);
        LiteAssert::same('A', $v['address']);
        LiteAssert::same(null, $v['city']);
        LiteAssert::same(null, $v['country']);
        LiteAssert::same('x@example.test', $v['email'], 'invalid mapping value → default');
        LiteAssert::false(array_key_exists('evil', ContactFieldMapper::sanitize($mapping)));
    },

    'generic phone: never both mobile and landline; auto = by kind, unclear numbers skipped' => function () {
        $map = fn (string $phone, string $mode) => ContactFieldMapper::map(['phone' => $phone], ['phone' => $mode] + ContactFieldMapper::defaults());
        LiteAssert::same(['09121112233', null], array_values(array_intersect_key($map('09121112233', 'auto'), array_flip(['mobile', 'landline']))));
        LiteAssert::same([null, '02188776655'], array_values(array_intersect_key($map('021-8877 6655', 'auto'), array_flip(['mobile', 'landline']))));
        LiteAssert::same([null, null], array_values(array_intersect_key($map('+44 20 7946 0958', 'auto'), array_flip(['mobile', 'landline']))), 'foreign: kind unclear');
        LiteAssert::same([null, null], array_values(array_intersect_key($map('02188776655', 'mobile'), array_flip(['mobile', 'landline']))));
        LiteAssert::same([null, '09121112233'], array_values(array_intersect_key($map('09121112233', 'landline'), array_flip(['mobile', 'landline']))));
        LiteAssert::same([null, null], array_values(array_intersect_key($map('09121112233', ''), array_flip(['mobile', 'landline']))));
        foreach (['auto', 'mobile', 'landline'] as $mode) {
            foreach (['09121112233', '02188776655', '+44 20 7946 0958', '12'] as $phone) {
                $v = $map($phone, $mode);
                LiteAssert::false($v['mobile'] !== null && $v['landline'] !== null, "both filled for {$phone} / {$mode}");
            }
        }
    },

    // --- Settings ----------------------------------------------------------------------

    'settings: switching on needs a contact group; the source defaults to the shop domain' => function () use ($setup, $h) {
        $setup();
        $service = $h->a->container()->make(ContactSyncSettingsService::class);
        $errors = $service->save($h->a->admin, $h->connId, ['enabled' => '1', 'category_id' => '', 'map' => ContactFieldMapper::defaults()], 'https://www.lite-shop.example.com/');
        LiteAssert::same('woocommerce.contacts.error.category_required', $errors['category_id'] ?? null);
        LiteAssert::same('lite-shop.example.com', ContactSyncSettingsService::defaultSource('https://www.lite-shop.example.com/store'));
        LiteAssert::same(0, (int) $h->a->pdo->query('SELECT COUNT(*) FROM woo_contact_sync_settings WHERE connection_id = ' . $h->connId)->fetchColumn(), 'nothing saved on error');
    },

    'settings: "create group" reuses an existing group of the same name; no duplicate group' => function () use ($setup, $h, $count) {
        $setup();
        $service = $h->a->container()->make(ContactSyncSettingsService::class);
        $before = $count("SELECT COUNT(*) FROM contact_categories WHERE organization_id = {$h->a->orgId}");
        $errors = $service->save($h->a->admin, $h->connId, ['enabled' => '0', 'new_category' => '  lite SHOP customers ', 'map' => ContactFieldMapper::defaults()], 'https://www.lite-shop.example.com/');
        LiteAssert::same([], $errors);
        LiteAssert::same($before, $count("SELECT COUNT(*) FROM contact_categories WHERE organization_id = {$h->a->orgId}"));
        LiteAssert::same($h->categoryId, $count("SELECT category_id FROM woo_contact_sync_settings WHERE connection_id = {$h->connId}"));
    },

    'settings: permissions — creating a group needs contacts.manage_categories; switching on needs contacts.create + contacts.edit' => function () use ($setup, $h) {
        $setup();
        if ($h->a->member === null) {
            throw new LiteSkip('needs a second user');
        }
        $service = $h->a->container()->make(ContactSyncSettingsService::class);
        // The system member role: no contacts.manage_categories.
        $errors = $service->save($h->a->member, $h->connId, ['enabled' => '1', 'new_category' => 'Member group', 'map' => ContactFieldMapper::defaults()], 'https://x.example');
        LiteAssert::same('woocommerce.contacts.error.category_permission', $errors['new_category'] ?? null);
        // A user without contact permissions cannot switch the sync on (it creates contacts in their name).
        $errors = $service->save($h->a->noRole, $h->connId, ['enabled' => '1', 'category_id' => (string) $h->categoryId, 'map' => ContactFieldMapper::defaults()], 'https://x.example');
        LiteAssert::same('woocommerce.contacts.error.contacts_permission', $errors['enabled'] ?? null);
        LiteAssert::same(0, (int) $h->a->pdo->query("SELECT COUNT(*) FROM woo_contact_sync_settings WHERE connection_id = {$h->connId} AND enabled = 1")->fetchColumn());
        LiteAssert::same(0, (int) $h->a->pdo->query("SELECT COUNT(*) FROM contact_categories WHERE name = 'Member group'")->fetchColumn());
    },

    'settings: a mapping without email and mobile is refused (no customer could be identified)' => function () use ($setup, $h) {
        $setup();
        $service = $h->a->container()->make(ContactSyncSettingsService::class);
        $errors = $service->save($h->a->admin, $h->connId, ['enabled' => '1', 'category_id' => (string) $h->categoryId, 'map' => ['email' => '', 'phone' => 'landline'] + ContactFieldMapper::defaults()], 'https://x.example');
        LiteAssert::same('woocommerce.contacts.error.no_identifier_mapped', $errors['map'] ?? null);
    },

    // --- Sync off ------------------------------------------------------------------------

    'sync off: importing an order creates no contact (Phase 1 behavior kept)' => function () use ($setup, $h, $order, $customer, $count) {
        $setup();
        $contacts = $count("SELECT COUNT(*) FROM contacts WHERE organization_id = {$h->a->orgId}");
        $h->importer->importOrder($h->connId, $order(['first_name' => 'Off', 'last_name' => 'Lite', 'email' => 'off.lite@example.test', 'phone' => '09125550000']));
        LiteAssert::same($contacts, $count("SELECT COUNT(*) FROM contacts WHERE organization_id = {$h->a->orgId}"));
        $row = $customer('off.lite@example.test');
        LiteAssert::same(null, $row['contact_sync_status'], 'pending until the sync is switched on');
        LiteAssert::same(null, $row['contact_id']);
    },

    // --- Sync on -----------------------------------------------------------------------

    'switch on: settings saved (group, tags, source, owner); existing customers processed by the manual run' => function () use ($setup, $h, $customer, $contact) {
        $setup();
        $h->a->as($h->a->admin);
        $res = $h->a->request('POST', '/org/integrations/' . $h->connId . '/contact-sync', [], [
            'enabled' => '1', 'category_id' => (string) $h->categoryId, 'tags' => 'Woo lite, VIP lite, Woo lite', 'source' => '', 'map' => ContactFieldMapper::defaults(),
        ]);
        LiteAssert::same(302, $res['status']);
        $settings = $h->a->pdo->query("SELECT * FROM woo_contact_sync_settings WHERE connection_id = {$h->connId}")->fetch();
        LiteAssert::same('1', (string) $settings['enabled']);
        LiteAssert::same('lite-shop.example.com', $settings['source']);
        LiteAssert::same((int) $h->a->admin['id'], (int) $settings['owner_user_id']);
        LiteAssert::same(2, count(json_decode($settings['tag_ids'], true)), 'duplicate tag names collapse');
        $h->tagIds = json_decode($settings['tag_ids'], true);

        $res = $h->a->request('POST', '/org/integrations/' . $h->connId . '/contact-sync/run');
        LiteAssert::same(302, $res['status']);
        $row = $customer('off.lite@example.test');
        LiteAssert::same('created', $row['contact_sync_status']);
        $c = $contact((int) $row['contact_id']);
        LiteAssert::same('Off', $c['first_name']);
        LiteAssert::same($h->categoryId, (int) $c['category_id']);
        LiteAssert::same('lite-shop.example.com', $c['source']);
        LiteAssert::same((int) $h->a->admin['id'], (int) $c['owner_id']);
        LiteAssert::same('09125550000', $c['phone']);
        LiteAssert::same(2, (int) $h->a->pdo->query("SELECT COUNT(*) FROM taggables WHERE taggable_type = 'contact' AND taggable_id = {$c['id']}")->fetchColumn());
        $summary = json_decode((string) $h->a->pdo->query("SELECT last_run_summary FROM woo_contact_sync_settings WHERE connection_id = {$h->connId}")->fetchColumn(), true);
        LiteAssert::true(($summary['created'] ?? 0) >= 1);
    },

    'new customer: contact created at import with mapped fields, mobile + email rows, linked before the order event' => function () use ($setup, $h, $order, $customer, $contact) {
        $setup();
        $h->nedaBilling = [
            'first_name' => 'Neda', 'last_name' => 'Karimi', 'email' => 'neda.lite@example.test', 'phone' => '0912 555 0001',
            'address_1' => 'Hafez St.', 'address_2' => '', 'city' => 'Shiraz', 'state' => 'FRS', 'postcode' => '7134567890', 'country' => 'IR',
        ];
        $h->importer->importOrder($h->connId, $order($h->nedaBilling));
        $row = $customer('neda.lite@example.test');
        LiteAssert::same('created', $row['contact_sync_status']);
        $c = $contact((int) $row['contact_id']);
        LiteAssert::same('Karimi', $c['last_name']);
        LiteAssert::same('Hafez St.', $c['address']);
        LiteAssert::same('Shiraz', $c['city']);
        LiteAssert::same('فارس', $c['province']);
        LiteAssert::same('7134567890', $c['postal_code']);
        LiteAssert::same('neda.lite@example.test', $c['email']);
        $phones = $h->a->pdo->query("SELECT type, number, is_primary FROM contact_phones WHERE contact_id = {$c['id']}")->fetchAll();
        LiteAssert::same([['type' => 'mobile', 'number' => '09125550001', 'is_primary' => 1]], array_map(fn ($p) => ['type' => $p['type'], 'number' => $p['number'], 'is_primary' => (int) $p['is_primary']], $phones));
        LiteAssert::same(1, (int) $h->a->pdo->query("SELECT COUNT(*) FROM contact_emails WHERE contact_id = {$c['id']} AND is_primary = 1")->fetchColumn());
        $h->nedaContactId = (int) $c['id'];
    },

    'existing contact matched by mobile in another stored form: only empty fields filled, source and group kept, tags added once' => function () use ($setup, $h, $order, $customer, $contact, $count) {
        $setup();
        $pdo = $h->a->pdo;
        $pdo->prepare("INSERT INTO contact_categories (organization_id, name) VALUES (?, 'Lite VIP')")->execute([$h->a->orgId]);
        $vip = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO contacts (organization_id, first_name, last_name, phone, city, source, category_id, status) VALUES (?, 'Reza', 'Original', '+989125550002', 'Karaj', 'Exhibition', ?, 'active')")->execute([$h->a->orgId, $vip]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO contact_phones (organization_id, contact_id, type, number, is_primary) VALUES (?, ?, 'mobile', '+989125550002', 1)")->execute([$h->a->orgId, $id]);
        $pdo->prepare("INSERT INTO taggables (tag_id, taggable_type, taggable_id) VALUES (?, 'contact', ?)")->execute([$h->tagIds[0], $id]);

        $h->importer->importOrder($h->connId, $order([
            'first_name' => 'R.', 'last_name' => 'Changed', 'email' => 'reza.lite@example.test', 'phone' => '09125550002',
            'address_1' => 'Azadi Sq.', 'city' => 'Tehran', 'state' => 'THR', 'postcode' => '', 'country' => 'IR',
        ]));
        $row = $customer('reza.lite@example.test');
        LiteAssert::same('updated', $row['contact_sync_status']);
        LiteAssert::same($id, (int) $row['contact_id']);
        $c = $contact($id);
        LiteAssert::same('Reza', $c['first_name'], 'never overwritten');
        LiteAssert::same('Original', $c['last_name'], 'never overwritten');
        LiteAssert::same('Karaj', $c['city'], 'never overwritten');
        LiteAssert::same('Exhibition', $c['source'], 'source preserved');
        LiteAssert::same($vip, (int) $c['category_id'], 'existing group kept');
        LiteAssert::same('Azadi Sq.', $c['address'], 'empty field filled');
        LiteAssert::same('تهران', $c['province']);
        LiteAssert::same('reza.lite@example.test', $c['email']);
        LiteAssert::same(null, $c['postal_code'], 'empty incoming value never written');
        LiteAssert::same(1, $count("SELECT COUNT(*) FROM contact_phones WHERE contact_id = {$id}"), 'no second mobile');
        LiteAssert::same(2, $count("SELECT COUNT(*) FROM taggables WHERE taggable_type = 'contact' AND taggable_id = {$id}"), 'tags added once each');
        $h->rezaId = $id;
    },

    'conflict: email of one contact and mobile of another → reported, nothing merged, nothing changed' => function () use ($setup, $h, $order, $customer, $count) {
        $setup();
        $pdo = $h->a->pdo;
        $pdo->prepare("INSERT INTO contacts (organization_id, first_name, email, status) VALUES (?, 'Mina', 'mina.lite@example.test', 'active')")->execute([$h->a->orgId]);
        $mina = (int) $pdo->lastInsertId();
        $before = $count("SELECT COUNT(*) FROM contacts WHERE organization_id = {$h->a->orgId}");
        $h->importer->importOrder($h->connId, $order(['first_name' => 'Mina', 'last_name' => 'X', 'email' => 'mina.lite@example.test', 'phone' => '09125550002', 'city' => 'Qom']));
        $row = $customer('mina.lite@example.test');
        LiteAssert::same('conflict', $row['contact_sync_status']);
        LiteAssert::same('woocommerce.contacts.note.identifiers_disagree', $row['contact_sync_note']);
        LiteAssert::true((int) $row['contact_id'] !== $h->rezaId, 'never linked to the mobile\'s contact');
        LiteAssert::same($before, $count("SELECT COUNT(*) FROM contacts WHERE organization_id = {$h->a->orgId}"));
        LiteAssert::same(null, $pdo->query("SELECT last_name FROM contacts WHERE id = {$mina}")->fetchColumn());
        LiteAssert::same('Karaj', $pdo->query("SELECT city FROM contacts WHERE id = {$h->rezaId}")->fetchColumn());
    },

    'ambiguous: two contacts with the same email → conflict, skipped' => function () use ($setup, $h, $order, $customer) {
        $setup();
        $pdo = $h->a->pdo;
        $pdo->prepare("INSERT INTO contacts (organization_id, first_name, email, status) VALUES (?, 'Twin A', 'twin.lite@example.test', 'active')")->execute([$h->a->orgId]);
        $pdo->prepare("INSERT INTO contacts (organization_id, first_name, status) VALUES (?, 'Twin B', 'active')")->execute([$h->a->orgId]);
        $twinB = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO contact_emails (organization_id, contact_id, email, is_primary) VALUES (?, ?, 'twin.lite@example.test', 0)")->execute([$h->a->orgId, $twinB]);
        $h->importer->importOrder($h->connId, $order(['first_name' => 'Twin', 'email' => 'twin.lite@example.test']));
        $row = $customer('twin.lite@example.test');
        LiteAssert::same('conflict', $row['contact_sync_status']);
        LiteAssert::same('woocommerce.contacts.note.ambiguous', $row['contact_sync_note']);
    },

    'not reliable enough to create: no first name, or no email and no mobile → skipped with a reason' => function () use ($setup, $h, $order, $customer) {
        $setup();
        $h->importer->importOrder($h->connId, $order(['first_name' => '', 'last_name' => 'Nameless', 'email' => 'noname.lite@example.test']));
        LiteAssert::same('woocommerce.contacts.note.missing_name', $customer('noname.lite@example.test')['contact_sync_note']);
        // Landline only: the customer is kept (phone-only guest) but no contact is created.
        $h->importer->importOrder($h->connId, $order(['first_name' => 'Fixed', 'phone' => '02144445555']));
        $row = $h->a->pdo->query("SELECT * FROM woo_customers WHERE connection_id = {$h->connId} AND phone = '02144445555'")->fetch();
        LiteAssert::same('skipped', $row['contact_sync_status']);
        LiteAssert::same('woocommerce.contacts.note.no_identifier', $row['contact_sync_note']);
    },

    'phone-only guest checkout: customer stored and contact created from the mobile' => function () use ($setup, $h, $order) {
        $setup();
        $h->importer->importOrder($h->connId, $order(['first_name' => 'Guest', 'last_name' => 'Phone', 'phone' => '۰۹۱۲۵۵۵۰۰۰۹']));
        $row = $h->a->pdo->query("SELECT * FROM woo_customers WHERE connection_id = {$h->connId} AND external_key LIKE 'guest-phone:%' AND first_name = 'Guest'")->fetch();
        LiteAssert::same('created', $row['contact_sync_status']);
        LiteAssert::same('09125550009', $h->a->pdo->query('SELECT phone FROM contacts WHERE id = ' . (int) $row['contact_id'])->fetchColumn());
    },

    'idempotent: the same order again, a repeated run and a changed order never duplicate contacts, numbers, emails or tags' => function () use ($setup, $h, $order, $customer, $count) {
        $setup();
        $orgContacts = fn () => $count("SELECT COUNT(*) FROM contacts WHERE organization_id = {$h->a->orgId}");
        $before = [$orgContacts(), $count('SELECT COUNT(*) FROM contact_phones'), $count('SELECT COUNT(*) FROM contact_emails'), $count('SELECT COUNT(*) FROM taggables')];
        $payload = $order($h->nedaBilling); // a second order with the same billing details
        $h->importer->importOrder($h->connId, $payload);
        $h->importer->importOrder($h->connId, $payload);
        $summary = OrganizationContext::runAs($h->a->orgId, fn () => $h->sync->processPending($h->connId, 100, microtime(true) + 10));
        LiteAssert::same(0, $summary['created']);
        LiteAssert::same($before, [$orgContacts(), $count('SELECT COUNT(*) FROM contact_phones'), $count('SELECT COUNT(*) FROM contact_emails'), $count('SELECT COUNT(*) FROM taggables')]);
        LiteAssert::same($h->nedaContactId, (int) $customer('neda.lite@example.test')['contact_id']);

        // Unchanged data does not make the customer pending again; changed data does (and is processed at import).
        LiteAssert::same('created', $customer('neda.lite@example.test')['contact_sync_status'], 'unchanged → not re-processed');
        $h->importer->importOrder($h->connId, $order(['postcode' => '7100000000'] + $h->nedaBilling));
        LiteAssert::same('matched', $customer('neda.lite@example.test')['contact_sync_status'], 'postal code already set → nothing to fill');
        LiteAssert::same('7134567890', $h->a->pdo->query("SELECT postal_code FROM contacts WHERE id = {$h->nedaContactId}")->fetchColumn());
    },

    'contact page: "Shop orders" panel lists the linked customer\'s existing orders with the shop name' => function () use ($setup, $h) {
        $setup();
        $h->a->as($h->a->admin);
        $res = $h->a->request('GET', '/contacts/' . $h->nedaContactId);
        LiteAssert::same(200, $res['status']);
        LiteAssert::contains('Shop orders', $res['body']);
        LiteAssert::contains('Lite shop', $res['body']);
        $orders = (int) $h->a->pdo->query("SELECT COUNT(*) FROM woo_orders o JOIN woo_customers c ON c.id = o.customer_id WHERE c.contact_id = {$h->nedaContactId}")->fetchColumn();
        LiteAssert::true($orders >= 3);
        LiteAssert::same(0, (int) $h->a->pdo->query("SELECT COUNT(*) FROM woo_orders WHERE organization_id <> {$h->a->orgId} AND connection_id = {$h->connId}")->fetchColumn());
    },

    'settings page renders (en, fa) with counts, mapping and the conflict list; integrations card links to it' => function () use ($setup, $h) {
        $setup();
        $h->a->as($h->a->admin);
        foreach (['en', 'fa'] as $locale) {
            $h->a->locale($locale);
            $body = $h->a->request('GET', '/org/integrations/' . $h->connId . '/contact-sync')['body'];
            LiteAssert::notContains('woocommerce.contacts.', $body, "no raw keys ({$locale})");
            LiteAssert::contains('mina.lite@example.test', $body);
        }
        $h->a->locale('en');
        LiteAssert::contains('/org/integrations/' . $h->connId . '/contact-sync', $h->a->request('GET', '/org/integrations')['body']);
    },

    'access: organization.edit needed; another organization\'s shop is not found; module off → no access' => function () use ($setup, $h) {
        $setup();
        if ($h->a->member !== null) {
            $h->a->as($h->a->member);
            LiteAssert::same('forbidden', $h->a->outcome('GET', '/org/integrations/' . $h->connId . '/contact-sync'));
            LiteAssert::same('forbidden', $h->a->outcome('POST', '/org/integrations/' . $h->connId . '/contact-sync/run'));
        }
        $h->a->as($h->a->admin);
        if ($h->a->otherOrgId !== null) {
            $h->a->pdo->prepare("INSERT INTO integration_connections (organization_id, provider, name, enabled, status, config_json, webhook_key) VALUES (?, 'woocommerce', 'Foreign', 1, 'configured', '{}', ?)")
                ->execute([$h->a->otherOrgId, bin2hex(random_bytes(20))]);
            LiteAssert::same('notfound', $h->a->outcome('GET', '/org/integrations/' . (int) $h->a->pdo->lastInsertId() . '/contact-sync'));
        }
        $h->a->setOrgEntitled('woocommerce', false);
        LiteAssert::true(in_array($h->a->outcome('GET', '/org/integrations/' . $h->connId . '/contact-sync'), ['forbidden', 'notfound', 'status:403'], true));
        $h->a->setOrgEntitled('woocommerce', true);
        $h->a->container()->make(OrganizationEntitlements::class)->reset();
    },

    'organization isolation: a contact of another organization with the same email is never matched or changed' => function () use ($setup, $h, $order, $customer) {
        $setup();
        if ($h->a->otherOrgId === null) {
            throw new LiteSkip('needs a second organization');
        }
        $pdo = $h->a->pdo;
        $pdo->prepare("INSERT INTO contacts (organization_id, first_name, email, status) VALUES (?, 'Foreign', 'iso.lite@example.test', 'active')")->execute([$h->a->otherOrgId]);
        $foreign = (int) $pdo->lastInsertId();
        $h->importer->importOrder($h->connId, $order(['first_name' => 'Iso', 'email' => 'iso.lite@example.test', 'city' => 'Yazd']));
        $row = $customer('iso.lite@example.test');
        LiteAssert::same('created', $row['contact_sync_status']);
        LiteAssert::true((int) $row['contact_id'] !== $foreign);
        LiteAssert::same($h->a->orgId, (int) $pdo->query('SELECT organization_id FROM contacts WHERE id = ' . (int) $row['contact_id'])->fetchColumn());
        LiteAssert::same(null, $pdo->query("SELECT city FROM contacts WHERE id = {$foreign}")->fetchColumn());
    },

    'contact group deleted after activation: the sync stops and says why; nothing is created' => function () use ($setup, $h, $order, $customer) {
        $setup();
        $pdo = $h->a->pdo;
        $pdo->exec("UPDATE woo_contact_sync_settings SET category_id = NULL WHERE connection_id = {$h->connId}");
        $h->importer->importOrder($h->connId, $order(['first_name' => 'Late', 'email' => 'late.lite@example.test', 'phone' => '09125550077']));
        LiteAssert::same(null, $customer('late.lite@example.test')['contact_sync_status'], 'left pending');
        LiteAssert::same('woocommerce.contacts.error.category_missing', $pdo->query("SELECT last_error FROM woo_contact_sync_settings WHERE connection_id = {$h->connId}")->fetchColumn());
        $pdo->exec("UPDATE woo_contact_sync_settings SET category_id = {$h->categoryId} WHERE connection_id = {$h->connId}");
    },

    'cron task: processes pending customers of enabled shops only, inside each organization' => function () use ($setup, $h, $customer) {
        $setup();
        $totals = $h->sync->processAllEnabled(10);
        LiteAssert::true($totals['created'] >= 1);
        LiteAssert::same('created', $customer('late.lite@example.test')['contact_sync_status']);
        LiteAssert::same($h->a->orgId, OrganizationContext::id(), 'context restored');
    },

    '"Sync contacts now" fetches the shop\'s latest orders first (no cron needed), then creates the contacts' => function () use ($setup, $h, $order, $customer) {
        $setup();
        $http = $h->http;
        // A sync job left waiting to retry by an earlier failure must not block the button.
        $h->a->pdo->exec("INSERT INTO integration_jobs (organization_id, connection_id, job_type, resource, dedupe_key, status, attempts, max_attempts, available_at, last_error)
            VALUES ({$h->a->orgId}, {$h->connId}, 'sync', 'orders', 'sync:{$h->connId}:orders', 'pending', 1, 5, UTC_TIMESTAMP() + INTERVAL 1 HOUR, 'woocommerce.error.unreachable')
            ON DUPLICATE KEY UPDATE status = 'pending', available_at = UTC_TIMESTAMP() + INTERVAL 1 HOUR");
        $http->pages[] = [$order(['first_name' => 'Button', 'last_name' => 'Lite', 'email' => 'button.lite@example.test', 'phone' => '09125550123'])];
        LiteAssert::same(null, $customer('button.lite@example.test'), 'not imported before the click');

        $h->a->as($h->a->admin);
        $res = $h->a->request('POST', '/org/integrations/' . $h->connId . '/contact-sync/run');
        LiteAssert::same(302, $res['status']);
        LiteAssert::true($http->calls >= 1, 'the shop was asked for its orders');
        $row = $customer('button.lite@example.test');
        LiteAssert::same('created', $row['contact_sync_status']);
        LiteAssert::same('Button', $h->a->pdo->query('SELECT first_name FROM contacts WHERE id = ' . (int) $row['contact_id'])->fetchColumn());
        $page = $h->a->request('GET', '/org/integrations/' . $h->connId . '/contact-sync')['body'];
        LiteAssert::contains('Shop import:', $page, 'the result is reported');
        LiteAssert::contains('Shop customers imported', $page);
    },

    'button off until the sync is on: the page says what to do; a shop without customers says why nothing happens' => function () use ($setup, $h) {
        $setup();
        $h->a->as($h->a->admin);
        $h->a->pdo->exec("UPDATE woo_contact_sync_settings SET enabled = 0 WHERE connection_id = {$h->connId}");
        LiteAssert::contains('To use this button', $h->a->request('GET', '/org/integrations/' . $h->connId . '/contact-sync')['body']);
        $h->a->pdo->exec("UPDATE woo_contact_sync_settings SET enabled = 1 WHERE connection_id = {$h->connId}");

        $created = $h->a->container()->make(IntegrationService::class)->create($h->a->admin, 'woocommerce', [
            'name' => 'Empty lite shop', 'store_url' => 'https://empty-lite.example.com', 'consumer_key' => 'ck_' . str_repeat('c3', 20), 'consumer_secret' => 'cs_' . str_repeat('d4', 20),
        ]);
        LiteAssert::contains('No customers have been imported', $h->a->request('GET', '/org/integrations/' . (int) $created['id'] . '/contact-sync')['body']);
    },

    'rollback leaves no test rows behind' => function () use ($setup, $h) {
        $setup();
        $h->a->rollBack();
        LiteAssert::same(0, (int) $h->a->pdo->query("SELECT COUNT(*) FROM woo_customers WHERE email LIKE '%.lite@example.test'")->fetchColumn());
    },
];
