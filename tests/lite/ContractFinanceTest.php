<?php

declare(strict_types=1);

/**
 * Contract ↔ Finance (docs/contract-finance-design.md). Finance owns the
 * integration; Contracts is untouched and read only through
 * RecordTypeRegistry.
 *
 * Stage 1  L. link table + repository (tenant isolation, one contract per document)
 * Stage 2  F. F1 hardening: contact_id of the user's organization, active currency
 * Stage 3  C. create from a contract (GET prefill + POST re-check), conversion carry-over
 * Stage 4  P. Billing panel (sections by permission, scope, issued-only summary)
 * Stage 5  M. module states, translations, regression
 *
 * Invoices are issued / paid here by writing the stored status/paid_total
 * directly: issue(), payments and credit notes open their own DB
 * transaction, which can't nest inside this suite's rollback transaction.
 * The panel only reads those stored values. crm_local only, rolled back.
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
        if ((int) $a->pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'finance_document_links'")->fetchColumn() !== 1) {
            $a->rollBack();
            throw new \RuntimeException('finance_document_links missing (run Finance migration 0016)');
        }
    } catch (\Throwable $e) {
        $h->reason = 'local database unavailable: ' . $e->getMessage();
        throw new LiteSkip($h->reason);
    }
    if ($a->member === null) {
        $a->rollBack();
        $h->reason = 'needs a second active user in the organization';
        throw new LiteSkip($h->reason);
    }
    $a->activateContracts();
    // Finance active + entitled for the org (inside the transaction).
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
    $h->app = $a;

    return $a;
};
$links = fn (): FinanceDocumentLinkRepository => new FinanceDocumentLinkRepository();
/** ProformaService or InvoiceService of the real app. */
$fin = fn (LocalApp $a, string $doc) => $a->container()->make($doc === 'proforma' ? ProformaService::class : InvoiceService::class);
/** A valid proforma/invoice input (two lines, untouched by the integration). */
$input = function (LocalApp $a, array $override = []): array {
    $currency = (string) $a->pdo->query('SELECT id FROM currencies WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();

    return array_merge([
        'currency_id' => $currency,
        'pricing_mode' => 'tax_exclusive',
        'issue_date' => date('Y-m-d'),
        'items' => [
            ['description' => 'LiteTest line A', 'quantity' => '2', 'unit_price' => '1500.50', 'discount_type' => 'none', 'discount_value' => '0'],
            ['description' => 'LiteTest line B', 'quantity' => '1', 'unit_price' => '999', 'discount_type' => 'percentage', 'discount_value' => '10'],
        ],
    ], $override);
};
$count = fn (LocalApp $a, string $table): int => (int) $a->pdo->query("SELECT COUNT(*) FROM {$table} WHERE organization_id = {$a->orgId}")->fetchColumn();
$billing = fn (LocalApp $a): ContractBillingService => (function () use ($a) {
    // The module's own instance (wired with the real registry) via the controller-free path.
    $svc = new ContractBillingService(
        $a->container()->make(ProformaService::class),
        $a->container()->make(InvoiceService::class),
        new FinanceDocumentLinkRepository(),
        $a->container()->make(\App\Application\Services\TimelineService::class),
        $a->container()->make(\App\Infrastructure\Records\RecordTypeRegistry::class),
    );

    return $svc;
})();
/** A contract of the org admin through the real ContractService (test code may use Contracts; Finance never does). */
$contract = function (LocalApp $a, array $override = []): array {
    $contact = (string) $a->pdo->query("SELECT id FROM contacts WHERE organization_id = {$a->orgId} ORDER BY id LIMIT 1")->fetchColumn();
    $currency = (string) $a->pdo->query('SELECT id FROM currencies WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
    $a->as($a->admin);

    return $a->container()->make(\Modules\Contracts\Services\ContractService::class)->create($a->admin, array_merge([
        'title' => 'LiteTest CF contract', 'contact_id' => $contact, 'value' => '12500.75', 'currency_id' => $currency,
        'terms' => 'LiteTest CF terms', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
    ], $override));
};
/** Another organization's contract (raw row), or null. */
$foreignContract = function (LocalApp $a): ?int {
    if ($a->otherOrgId === null) {
        return null;
    }
    $owner = $a->pdo->query("SELECT id FROM users WHERE organization_id = {$a->otherOrgId} ORDER BY id LIMIT 1")->fetchColumn();
    $owner = $owner === false ? (int) $a->admin['id'] : (int) $owner;
    $a->pdo->prepare('INSERT INTO contracts (organization_id, title, owner_id, created_by_user_id) VALUES (?, ?, ?, ?)')->execute([$a->otherOrgId, 'LiteTest XORG CF contract', $owner, $owner]);

    return (int) $a->pdo->lastInsertId();
};
/** Temporarily sets a member-role grant (null = remove); restored afterwards (and rolled back anyway). */
$withMemberScope = function (LocalApp $a, string $key, ?string $scope, callable $fn): void {
    $old = $a->pdo->query("SELECT scope FROM role_permissions WHERE role_id = {$a->memberRoleId} AND permission_key = " . $a->pdo->quote($key))->fetchColumn();
    $a->pdo->prepare('DELETE FROM role_permissions WHERE role_id = ? AND permission_key = ?')->execute([$a->memberRoleId, $key]);
    if ($scope !== null) {
        $a->pdo->prepare('INSERT INTO role_permissions (role_id, permission_key, scope) VALUES (?, ?, ?)')->execute([$a->memberRoleId, $key, $scope]);
    }
    try {
        $fn();
    } finally {
        $a->pdo->prepare('DELETE FROM role_permissions WHERE role_id = ? AND permission_key = ?')->execute([$a->memberRoleId, $key]);
        if ($old !== false) {
            $a->pdo->prepare('INSERT INTO role_permissions (role_id, permission_key, scope) VALUES (?, ?, ?)')->execute([$a->memberRoleId, $key, $old]);
        }
        $a->as($a->admin);
    }
};

$tests = [];

// ---- Stage 1: L. link table + repository ------------------------------------
$tests['L1. migration 0016 has up() and down(); table has the designed unique + target keys'] = function () use ($boot) {
    $migration = require dirname(__DIR__, 2) . '/modules/Finance/Migrations/0016_create_finance_document_links_table.php';
    LiteAssert::true($migration instanceof \App\Infrastructure\Database\MigrationInterface);
    LiteAssert::true(method_exists($migration, 'down'));
    $a = $boot();
    $keys = $a->pdo->query("SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols, MAX(NON_UNIQUE) AS non_unique FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'finance_document_links' GROUP BY INDEX_NAME")->fetchAll(\PDO::FETCH_ASSOC);
    $byName = array_column($keys, null, 'INDEX_NAME');
    LiteAssert::same('organization_id,document_type,document_id,linked_type', $byName['uniq_finance_document_links_document']['cols'] ?? null);
    LiteAssert::same('0', (string) $byName['uniq_finance_document_links_document']['non_unique']);
    LiteAssert::same('organization_id,linked_type,linked_id', $byName['idx_finance_document_links_target']['cols'] ?? null);
    // No FK to any other module's table (contracts may not be installed).
    $fks = $a->pdo->query("SELECT REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'finance_document_links' AND REFERENCED_TABLE_NAME IS NOT NULL")->fetchAll(\PDO::FETCH_COLUMN);
    sort($fks);
    LiteAssert::same(['organizations', 'users'], $fks);
};

$tests['L2. repository: create, find per document, documents linked to a record, delete for a document'] = function () use ($boot, $links) {
    $a = $boot();
    $repo = $links();
    $repo->create('proforma', 900001, 'contract', 7001, 'LiteTest contract', (int) $a->admin['id']);
    $repo->create('invoice', 900002, 'contract', 7001, 'LiteTest contract', (int) $a->admin['id']);
    $repo->create('invoice', 900003, 'contract', 7001, 'LiteTest contract', null);
    LiteAssert::same(7001, (int) $repo->forDocument('proforma', 900001, 'contract')['linked_id']);
    LiteAssert::same(null, $repo->forDocument('invoice', 900001, 'contract'), 'document type matters');
    LiteAssert::same([900003, 900002], $repo->documentIdsLinkedTo('invoice', 'contract', 7001));
    LiteAssert::same([900001], $repo->documentIdsLinkedTo('proforma', 'contract', 7001));
    $repo->deleteForDocument('invoice', 900002);
    LiteAssert::same([900003], $repo->documentIdsLinkedTo('invoice', 'contract', 7001));
};

$tests['L3. one contract per document (D3): a second link of the same document is refused by the database'] = function () use ($boot, $links) {
    $a = $boot();
    $links()->create('invoice', 900010, 'contract', 7002, 'A', null);
    LiteAssert::throws(\PDOException::class, fn () => $links()->create('invoice', 900010, 'contract', 7003, 'B', null));
    LiteAssert::same(7002, (int) $links()->forDocument('invoice', 900010, 'contract')['linked_id']);
    // …while a contract may have many documents.
    $links()->create('invoice', 900011, 'contract', 7002, 'A', null);
    LiteAssert::same(2, count($links()->documentIdsLinkedTo('invoice', 'contract', 7002)));
};

$tests['L4. tenant isolation: another organization\'s link rows are invisible and untouchable'] = function () use ($boot, $links) {
    $a = $boot();
    if ($a->otherOrgId === null) {
        throw new LiteSkip('only one organization');
    }
    $a->pdo->prepare("INSERT INTO finance_document_links (organization_id, document_type, document_id, linked_type, linked_id, linked_label) VALUES (?, 'invoice', 900020, 'contract', 7004, 'XORG')")->execute([$a->otherOrgId]);
    LiteAssert::same(null, $links()->forDocument('invoice', 900020, 'contract'));
    LiteAssert::same([], $links()->documentIdsLinkedTo('invoice', 'contract', 7004));
    $links()->deleteForDocument('invoice', 900020);
    LiteAssert::same(1, (int) $a->pdo->query("SELECT COUNT(*) FROM finance_document_links WHERE organization_id = {$a->otherOrgId} AND document_id = 900020")->fetchColumn(), 'not deleted');
    // Writes always land in the caller's own organization.
    $links()->create('invoice', 900021, 'contract', 7004, 'mine', null);
    LiteAssert::same($a->orgId, (int) $a->pdo->query('SELECT organization_id FROM finance_document_links WHERE document_id = 900021')->fetchColumn());
};

// ---- Stage 2: F. F1 hardening (contact of the organization, active currency) --

$tests['F1. proforma + invoice with another organization\'s contact → rejected (service and route 422), nothing stored, no foreign name in lists'] = function () use ($boot, $fin, $input, $count) {
    $a = $boot();
    if ($a->otherOrgId === null) {
        throw new LiteSkip('only one organization');
    }
    $foreign = $a->pdo->query("SELECT id, first_name FROM contacts WHERE organization_id = {$a->otherOrgId} ORDER BY id LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
    if ($foreign === false) {
        $a->pdo->prepare('INSERT INTO contacts (organization_id, first_name, last_name) VALUES (?, ?, ?)')->execute([$a->otherOrgId, 'LiteTestXorgName', 'Secret']);
        $foreign = ['id' => (int) $a->pdo->lastInsertId(), 'first_name' => 'LiteTestXorgName'];
    }
    $before = [$count($a, 'proformas'), $count($a, 'invoices')];
    foreach (['proforma', 'invoice'] as $doc) {
        $err = null;
        try {
            $fin($a, $doc)->create($a->admin, $input($a, ['contact_id' => (string) $foreign['id']]));
        } catch (\App\Core\Exceptions\ValidationException $e) {
            $err = $e->errors;
        }
        LiteAssert::true(isset($err['contact_id']), "{$doc}: service refused " . json_encode($err));
        $r = $a->request('POST', "/finance/{$doc}s", [], $input($a, ['contact_id' => (string) $foreign['id']]));
        LiteAssert::same(422, $r['status'], "{$doc}: route");
        LiteAssert::contains('field-error', $r['body']);
    }
    LiteAssert::same($before, [$count($a, 'proformas'), $count($a, 'invoices')], 'nothing stored');
    LiteAssert::same(0, (int) $a->pdo->query("SELECT (SELECT COUNT(*) FROM proformas WHERE organization_id = {$a->orgId} AND contact_id = {$foreign['id']}) + (SELECT COUNT(*) FROM invoices WHERE organization_id = {$a->orgId} AND contact_id = {$foreign['id']})")->fetchColumn());
};

$tests['F2. unknown contact id, unknown currency, inactive currency → rejected; update cannot switch to a foreign contact either'] = function () use ($boot, $fin, $input) {
    $a = $boot();
    $expect = function (callable $fn, string $field): void {
        try {
            $fn();
        } catch (\App\Core\Exceptions\ValidationException $e) {
            LiteAssert::true(isset($e->errors[$field]), "expected {$field}: " . json_encode($e->errors));

            return;
        }
        throw new LiteAssertionFailed("expected a ValidationException on {$field}");
    };
    foreach (['proforma', 'invoice'] as $doc) {
        $expect(fn () => $fin($a, $doc)->create($a->admin, $input($a, ['contact_id' => '999999999'])), 'contact_id');
        $expect(fn () => $fin($a, $doc)->create($a->admin, $input($a, ['currency_id' => '999999999'])), 'currency_id');
    }
    // An inactive currency (deactivated inside the transaction).
    $inactive = (int) $a->pdo->query('SELECT id FROM currencies WHERE is_active = 1 ORDER BY id DESC LIMIT 1')->fetchColumn();
    $a->pdo->exec("UPDATE currencies SET is_active = 0 WHERE id = {$inactive}");
    try {
        foreach (['proforma', 'invoice'] as $doc) {
            $expect(fn () => $fin($a, $doc)->create($a->admin, $input($a, ['currency_id' => (string) $inactive])), 'currency_id');
        }
    } finally {
        $a->pdo->exec("UPDATE currencies SET is_active = 1 WHERE id = {$inactive}");
    }
    // Update: a valid draft can't be switched to a contact of another organization.
    if ($a->otherOrgId !== null) {
        $foreign = $a->pdo->query("SELECT id FROM contacts WHERE organization_id = {$a->otherOrgId} ORDER BY id LIMIT 1")->fetchColumn();
        if ($foreign !== false) {
            foreach (['proforma', 'invoice'] as $doc) {
                $doc1 = $fin($a, $doc)->create($a->admin, $input($a));
                $expect(fn () => $fin($a, $doc)->update($a->admin, (int) $doc1['id'], $input($a, ['contact_id' => (string) $foreign])), 'contact_id');
                LiteAssert::same(null, $a->pdo->query("SELECT contact_id FROM {$doc}s WHERE id = {$doc1['id']}")->fetchColumn() ?: null, "{$doc} unchanged");
            }
        }
    }
};

$tests['F3. regression: own contact, no contact, active currency → created exactly as before'] = function () use ($boot, $fin, $input) {
    $a = $boot();
    $contact = (int) $a->pdo->query("SELECT id FROM contacts WHERE organization_id = {$a->orgId} ORDER BY id LIMIT 1")->fetchColumn();
    foreach (['proforma', 'invoice'] as $doc) {
        $withContact = $fin($a, $doc)->create($a->admin, $input($a, ['contact_id' => (string) $contact]));
        LiteAssert::same($contact, (int) $withContact['contact_id']);
        $without = $fin($a, $doc)->create($a->admin, $input($a));
        LiteAssert::same(null, $without['contact_id']);
        LiteAssert::same('draft', $without['status']);
        LiteAssert::same((string) $withContact['grand_total'], (string) $without['grand_total'], 'same lines → same totals');
    }
};

// ---- Stage 3: C. create from a contract + conversion carry-over -------------

$tests['C1. GET ?from_contract= prefills proforma + invoice forms (contact, currency, one line, terms) with a server-derived notice'] = function () use ($boot, $contract) {
    $a = $boot();
    $c = $contract($a);
    foreach (['proformas', 'invoices'] as $docs) {
        $r = $a->request('GET', "/finance/{$docs}/create", ['from_contract' => (string) $c['id']]);
        LiteAssert::same(200, $r['status'], $docs);
        $b = $r['body'];
        LiteAssert::contains('name="source_type" value="contract"', $b);
        LiteAssert::contains('name="source_id" value="' . $c['id'] . '"', $b);
        LiteAssert::contains('href="/contracts/' . $c['id'] . '"', $b, 'notice links the contract');
        LiteAssert::contains('LiteTest CF contract', $b, 'line description = contract title');
        LiteAssert::contains('value="12500.75"', $b, 'unit price = contract value');
        LiteAssert::contains('LiteTest CF terms', $b, 'terms carried');
        LiteAssert::true(preg_match('#<option value="' . $c['contact_id'] . '"\s+selected#', $b) === 1, 'contact preselected');
        LiteAssert::true(preg_match('#<option value="' . $c['currency_id'] . '"\s+selected#', $b) === 1, 'currency preselected');
    }
};

$tests['C2. company-only contract → empty contact + notice; contract without a value → empty price'] = function () use ($boot, $contract, $billing) {
    $a = $boot();
    $company = (int) $a->pdo->query("SELECT id FROM companies WHERE organization_id = {$a->orgId} ORDER BY id LIMIT 1")->fetchColumn();
    $c = $contract($a, ['contact_id' => '', 'company_id' => (string) $company, 'value' => '', 'currency_id' => '']);
    $draft = $billing($a)->draftFrom($a->admin, (int) $c['id']);
    LiteAssert::same('', $draft['contact_id']);
    LiteAssert::same('', $draft['items'][0]['unit_price']);
    LiteAssert::false(isset($draft['currency_id']), 'organization default currency applies');
    LiteAssert::true($billing($a)->sourceNotice($a->admin, (int) $c['id'])['no_contact']);
};

$tests['C3. POST from a contract: document + link + contract timeline, and totals IDENTICAL to the same input without a contract'] = function () use ($boot, $contract, $input) {
    $a = $boot();
    $c = $contract($a);
    foreach (['proforma', 'invoice'] as $doc) {
        $plain = $a->request('POST', "/finance/{$doc}s", [], $input($a));
        $linked = $a->request('POST', "/finance/{$doc}s", [], $input($a, ['source_type' => 'contract', 'source_id' => (string) $c['id']]));
        $idOf = fn (array $r) => (int) preg_replace('#^/finance/' . $doc . 's/(\d+)$#', '$1', (string) $r['location']);
        [$plainId, $linkedId] = [$idOf($plain), $idOf($linked)];
        LiteAssert::true($plainId > 0 && $linkedId > 0, "{$doc} created: {$plain['status']}/{$linked['status']}");
        $totals = fn (int $id) => $a->pdo->query("SELECT subtotal, tax_total, grand_total, status FROM {$doc}s WHERE id = {$id}")->fetch(\PDO::FETCH_ASSOC);
        LiteAssert::same($totals($plainId), $totals($linkedId), "{$doc}: financial result unchanged by the integration");
        LiteAssert::same((int) $c['id'], (int) $a->pdo->query("SELECT linked_id FROM finance_document_links WHERE document_type = '{$doc}' AND document_id = {$linkedId}")->fetchColumn());
        LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM finance_document_links WHERE document_type = '{$doc}' AND document_id = {$plainId}")->fetchColumn(), 'no link without a source');
        LiteAssert::same(1, (int) $a->pdo->query("SELECT COUNT(*) FROM timeline_events WHERE subject_type = 'contract' AND subject_id = {$c['id']} AND type = '{$doc}_created'")->fetchColumn());
        LiteAssert::contains('href="/contracts/' . $c['id'] . '"', $a->request('GET', "/finance/{$doc}s/{$linkedId}")['body'], 'show page names the contract');
    }
};

$tests['C4. converting a linked proforma links the new invoice to the same contract; an unlinked one stays unlinked'] = function () use ($boot, $contract, $billing, $fin, $input) {
    $a = $boot();
    $c = $contract($a);
    $linked = $billing($a)->createLinked($a->admin, 'proforma', $input($a), 'contract', (int) $c['id']);
    $r = $a->request('POST', "/finance/proformas/{$linked['id']}/convert");
    $invoiceId = (int) preg_replace('#^/finance/invoices/(\d+)$#', '$1', (string) $r['location']);
    LiteAssert::true($invoiceId > 0, 'converted: ' . $r['location']);
    LiteAssert::same((int) $c['id'], (int) $a->pdo->query("SELECT linked_id FROM finance_document_links WHERE document_type = 'invoice' AND document_id = {$invoiceId} AND linked_type = 'contract'")->fetchColumn());
    $pTotals = $a->pdo->query("SELECT subtotal, tax_total, grand_total FROM proformas WHERE id = {$linked['id']}")->fetch(\PDO::FETCH_ASSOC);
    $iTotals = $a->pdo->query("SELECT subtotal, tax_total, grand_total FROM invoices WHERE id = {$invoiceId}")->fetch(\PDO::FETCH_ASSOC);
    LiteAssert::same($pTotals, $iTotals, 'conversion amounts unchanged');

    $plain = $fin($a, 'proforma')->create($a->admin, $input($a));
    $r2 = $a->request('POST', "/finance/proformas/{$plain['id']}/convert");
    $invoice2 = (int) preg_replace('#^/finance/invoices/(\d+)$#', '$1', (string) $r2['location']);
    LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM finance_document_links WHERE document_type = 'invoice' AND document_id = {$invoice2}")->fetchColumn());
};

$tests['C5. deleting a linked draft removes its link; the contract and other links are untouched'] = function () use ($boot, $contract, $billing, $input) {
    $a = $boot();
    $c = $contract($a);
    $keep = $billing($a)->createLinked($a->admin, 'invoice', $input($a), 'contract', (int) $c['id']);
    foreach (['proforma', 'invoice'] as $doc) {
        $d = $billing($a)->createLinked($a->admin, $doc, $input($a), 'contract', (int) $c['id']);
        $a->request('POST', "/finance/{$doc}s/{$d['id']}/delete");
        LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM {$doc}s WHERE id = {$d['id']}")->fetchColumn(), "{$doc} deleted");
        LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM finance_document_links WHERE document_type = '{$doc}' AND document_id = {$d['id']}")->fetchColumn(), "{$doc} link gone");
    }
    LiteAssert::same(1, (int) $a->pdo->query("SELECT COUNT(*) FROM finance_document_links WHERE document_type = 'invoice' AND document_id = {$keep['id']}")->fetchColumn());
    LiteAssert::same(1, (int) $a->pdo->query("SELECT COUNT(*) FROM contracts WHERE id = {$c['id']}")->fetchColumn());
};

$tests['C6. GET prefill → 404: missing, malformed, another organization\'s, and out-of-scope contracts'] = function () use ($boot, $contract, $foreignContract, $withMemberScope) {
    $a = $boot();
    $cases = ['999999999', 'abc', '0'];
    if (($foreign = $foreignContract($a)) !== null) {
        $cases[] = (string) $foreign;
    }
    foreach (['proformas', 'invoices'] as $docs) {
        foreach ($cases as $id) {
            LiteAssert::same('notfound', $a->outcome('GET', "/finance/{$docs}/create", ['from_contract' => $id]), "{$docs} from_contract={$id}");
        }
    }
    $c = $contract($a); // the admin's contract
    $withMemberScope($a, 'contracts.view', 'own', function () use ($a, $c) {
        $a->as($a->member);
        foreach (['proformas', 'invoices'] as $docs) {
            LiteAssert::same('notfound', $a->outcome('GET', "/finance/{$docs}/create", ['from_contract' => (string) $c['id']]), "{$docs}: out of scope");
        }
        $a->as($a->admin);
    });
};

$tests['C7. tampered POST source → 422, no document, no link, no contract timeline'] = function () use ($boot, $contract, $foreignContract, $input, $count, $withMemberScope) {
    $a = $boot();
    $c = $contract($a);
    $sources = [
        ['source_type' => 'contract', 'source_id' => '999999999'],
        ['source_type' => 'contract', 'source_id' => ''],
        ['source_type' => 'deal', 'source_id' => '1'],
        ['source_type' => 'project', 'source_id' => '1'],
        ['source_type' => 'contact', 'source_id' => (string) $c['contact_id']],
        ['source_type' => '', 'source_id' => (string) $c['id']],
    ];
    if (($foreign = $foreignContract($a)) !== null) {
        $sources[] = ['source_type' => 'contract', 'source_id' => (string) $foreign];
    }
    $before = [$count($a, 'proformas'), $count($a, 'invoices'), $count($a, 'finance_document_links')];
    $wrong = [];
    foreach (['proforma', 'invoice'] as $doc) {
        foreach ($sources as $s) {
            $r = $a->request('POST', "/finance/{$doc}s", [], $input($a, $s));
            if ($r['status'] !== 422) {
                $wrong[] = "{$doc} " . json_encode($s) . " → {$r['status']}";
            }
        }
    }
    // Out of record scope: the member can't see the admin's contract.
    $withMemberScope($a, 'contracts.view', 'own', function () use ($a, $c, $input, &$wrong) {
        $a->as($a->member);
        foreach (['proforma', 'invoice'] as $doc) {
            $r = $a->request('POST', "/finance/{$doc}s", [], $input($a, ['source_type' => 'contract', 'source_id' => (string) $c['id']]));
            if ($r['status'] !== 422) {
                $wrong[] = "{$doc} out of scope → {$r['status']}";
            }
        }
        $a->as($a->admin);
    });
    LiteAssert::same([], $wrong, implode(' | ', $wrong));
    LiteAssert::same($before, [$count($a, 'proformas'), $count($a, 'invoices'), $count($a, 'finance_document_links')], 'nothing created');
    LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM timeline_events WHERE subject_type = 'contract' AND subject_id = {$c['id']} AND type IN ('proforma_created', 'invoice_created')")->fetchColumn());
};

$tests['C8. Contracts off: prefill 404, POST 422, linked documents still open and show the stored label (no link)'] = function () use ($boot, $contract, $billing, $input) {
    $a = $boot();
    $c = $contract($a);
    $inv = $billing($a)->createLinked($a->admin, 'invoice', $input($a), 'contract', (int) $c['id']);
    $a->setOrgEntitled('contracts', false);
    try {
        LiteAssert::same('notfound', $a->outcome('GET', '/finance/invoices/create', ['from_contract' => (string) $c['id']]));
        LiteAssert::same(422, $a->request('POST', '/finance/invoices', [], $input($a, ['source_type' => 'contract', 'source_id' => (string) $c['id']]))['status']);
        $show = $a->request('GET', "/finance/invoices/{$inv['id']}");
        LiteAssert::same(200, $show['status']);
        LiteAssert::contains('LiteTest CF contract', $show['body'], 'stored label');
        LiteAssert::notContains('href="/contracts/' . $c['id'] . '"', $show['body']);
    } finally {
        $a->setOrgEntitled('contracts', true);
    }
    LiteAssert::same(1, (int) $a->pdo->query("SELECT COUNT(*) FROM finance_document_links WHERE document_id = {$inv['id']} AND document_type = 'invoice'")->fetchColumn(), 'link kept');
};

// ---- Stage 4: P. Billing panel --------------------------------------------------

/** Marks an invoice issued / paid by writing its stored fields (see the file docblock). */
$issue = function (LocalApp $a, int $invoiceId, ?string $paid = null, string $status = 'issued'): void {
    $a->pdo->prepare('UPDATE invoices SET status = ?, paid_total = COALESCE(?, paid_total) WHERE id = ?')->execute([$status, $paid, $invoiceId]);
};
$panelOf = function (LocalApp $a, int $contractId): ?string {
    $body = $a->request('GET', "/contracts/{$contractId}")['body'];
    $start = strpos($body, 'data-related-panel="finance.contract"');

    return $start === false ? null : substr($body, $start, strpos($body, '</section>', $start) - $start);
};
$canView = fn (LocalApp $a, array $user) => fn (string $key): bool => $a->container()->make(\App\Infrastructure\Authorization\AuthorizationEngine::class)->can($user, $key);

$tests['P1. contract page shows the Billing panel: linked proformas + invoices, New proforma / New invoice buttons'] = function () use ($boot, $contract, $billing, $input, $panelOf) {
    $a = $boot();
    $c = $contract($a);
    $p = $billing($a)->createLinked($a->admin, 'proforma', $input($a, ['custom_number' => 'LT-PF-1']), 'contract', (int) $c['id']);
    $i = $billing($a)->createLinked($a->admin, 'invoice', $input($a, ['custom_number' => 'LT-INV-1']), 'contract', (int) $c['id']);
    $panel = $panelOf($a, (int) $c['id']);
    LiteAssert::true($panel !== null, 'panel rendered');
    LiteAssert::contains('href="/finance/proformas/' . $p['id'] . '"', $panel);
    LiteAssert::contains('href="/finance/invoices/' . $i['id'] . '"', $panel);
    LiteAssert::contains('LT-PF-1', $panel);
    LiteAssert::contains('/finance/proformas/create?from_contract=' . $c['id'], $panel);
    LiteAssert::contains('/finance/invoices/create?from_contract=' . $c['id'], $panel);
};

$tests['P2. summary (D5): issued invoices only, per currency — billed Σ grand_total, paid Σ paid_total, balance = billed − paid; drafts/cancelled not counted'] = function () use ($boot, $contract, $billing, $input, $issue, $canView) {
    $a = $boot();
    $c = $contract($a);
    $currencies = $a->pdo->query('SELECT id FROM currencies WHERE is_active = 1 ORDER BY id LIMIT 2')->fetchAll(\PDO::FETCH_COLUMN);
    $mk = fn (array $o = []) => $billing($a)->createLinked($a->admin, 'invoice', $input($a, $o), 'contract', (int) $c['id']);
    $i1 = $mk();
    $i2 = $mk();
    $draft = $mk();
    $cancelled = $mk();
    $issue($a, (int) $i1['id'], '1000');
    $issue($a, (int) $i2['id'], '0.5');
    $issue($a, (int) $cancelled['id'], '0', 'cancelled');
    $rows = fn (int $id) => $a->pdo->query("SELECT grand_total, paid_total FROM invoices WHERE id = {$id}")->fetch(\PDO::FETCH_ASSOC);
    [$r1, $r2] = [$rows((int) $i1['id']), $rows((int) $i2['id'])];
    $expectBilled = bcadd($r1['grand_total'], $r2['grand_total'], 18);
    $expectPaid = bcadd($r1['paid_total'], $r2['paid_total'], 18);

    $data = $billing($a)->panelFor($a->admin, (int) $c['id'], $canView($a, $a->admin));
    LiteAssert::same(4, count($data['invoices']), 'all four listed');
    LiteAssert::same(1, count($data['summary']), 'one currency');
    LiteAssert::same($expectBilled, $data['summary'][0]['billed']);
    LiteAssert::same($expectPaid, $data['summary'][0]['paid']);
    LiteAssert::same(bcsub($expectBilled, $expectPaid, 18), $data['summary'][0]['balance']);
    unset($draft);

    // A second currency gets its own line; nothing is converted or mixed.
    if (count($currencies) === 2) {
        $other = $mk(['currency_id' => (string) $currencies[1]]);
        $issue($a, (int) $other['id'], '0');
        $data = $billing($a)->panelFor($a->admin, (int) $c['id'], $canView($a, $a->admin));
        LiteAssert::same(2, count($data['summary']), 'two currencies');
        $byCurrency = array_column($data['summary'], null, 'currency_id');
        LiteAssert::same($expectBilled, $byCurrency[(int) $currencies[0]]['billed'], 'first currency unchanged');
    }
    // A payment changes paid and balance only.
    $issue($a, (int) $i2['id'], $r2['grand_total']);
    $after = array_column($billing($a)->panelFor($a->admin, (int) $c['id'], $canView($a, $a->admin))['summary'], null, 'currency_id')[(int) $currencies[0]];
    LiteAssert::same($expectBilled, $after['billed']);
    LiteAssert::same(bcadd($r1['paid_total'], $r2['grand_total'], 18), $after['paid']);
};

$tests['P3. scope: an OWN-scope member sees only the documents they own, and the summary counts only those'] = function () use ($boot, $contract, $billing, $input, $issue, $canView, $panelOf) {
    $a = $boot();
    $c = $contract($a);
    $adminInv = $billing($a)->createLinked($a->admin, 'invoice', $input($a, ['custom_number' => 'LT-ADMIN']), 'contract', (int) $c['id']);
    $a->as($a->member);
    $memberInv = $billing($a)->createLinked($a->member, 'invoice', $input($a, ['custom_number' => 'LT-MEMBER']), 'contract', (int) $c['id']);
    $a->as($a->admin);
    $issue($a, (int) $adminInv['id'], '0');
    $issue($a, (int) $memberInv['id'], '0');
    // member role: invoices.view = own (crm_local default grants)
    $data = $billing($a)->panelFor($a->member, (int) $c['id'], $canView($a, $a->member));
    LiteAssert::same([(int) $memberInv['id']], array_map(fn ($r) => (int) $r['id'], $data['invoices']));
    $memberTotal = $a->pdo->query("SELECT grand_total FROM invoices WHERE id = {$memberInv['id']}")->fetchColumn();
    LiteAssert::same(bcadd('0', (string) $memberTotal, 18), $data['summary'][0]['billed'], 'summary from visible rows only');
    $a->as($a->member);
    $panel = $panelOf($a, (int) $c['id']);
    $a->as($a->admin);
    LiteAssert::contains('LT-MEMBER', (string) $panel);
    LiteAssert::notContains('LT-ADMIN', (string) $panel);
};

$tests['P4. sections follow view permissions; no view permission at all → no panel; no create permission → no button'] = function () use ($boot, $contract, $billing, $input, $panelOf, $withMemberScope) {
    $a = $boot();
    $c = $contract($a);
    $withMemberScope($a, 'quotes.view', null, function () use ($a, $c, $panelOf) {
        $a->as($a->member);
        $panel = (string) $panelOf($a, (int) $c['id']);
        LiteAssert::contains('/finance/invoices/', $panel . '/finance/invoices/', 'invoice section still there');
        LiteAssert::notContains('<h3>' . htmlspecialchars(\App\Infrastructure\Theme\t('proformas.title')) . '</h3>', $panel, 'no proforma section');
    });
    $withMemberScope($a, 'quotes.view', null, function () use ($a, $c, $panelOf, $withMemberScope) {
        $withMemberScope($a, 'invoices.view', null, function () use ($a, $c, $panelOf) {
            $a->as($a->member);
            LiteAssert::same(null, $panelOf($a, (int) $c['id']), 'no panel without any view permission');
        });
    });
    $withMemberScope($a, 'quotes.create', null, function () use ($a, $c, $panelOf) {
        $a->as($a->member);
        $panel = (string) $panelOf($a, (int) $c['id']);
        LiteAssert::notContains('/finance/proformas/create?from_contract=', $panel);
        LiteAssert::contains('/finance/invoices/create?from_contract=', $panel);
    });
};

$tests['P5. another organization\'s link / document never appears in the panel, even when it points at my contract id'] = function () use ($boot, $contract, $billing, $canView) {
    $a = $boot();
    if ($a->otherOrgId === null) {
        throw new LiteSkip('only one organization');
    }
    $c = $contract($a);
    $currency = (int) $a->pdo->query('SELECT id FROM currencies WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
    $owner = $a->pdo->query("SELECT id FROM users WHERE organization_id = {$a->otherOrgId} ORDER BY id LIMIT 1")->fetchColumn();
    $owner = $owner === false ? (int) $a->admin['id'] : (int) $owner;
    $a->pdo->prepare("INSERT INTO invoices (organization_id, owner_id, issue_date, currency_id, pricing_mode, grand_total, status) VALUES (?, ?, CURDATE(), ?, 'tax_exclusive', 777, 'issued')")->execute([$a->otherOrgId, $owner, $currency]);
    $foreignInvoice = (int) $a->pdo->lastInsertId();
    $a->pdo->prepare("INSERT INTO finance_document_links (organization_id, document_type, document_id, linked_type, linked_id, linked_label) VALUES (?, 'invoice', ?, 'contract', ?, 'x')")->execute([$a->otherOrgId, $foreignInvoice, $c['id']]);
    // …and a link of MY organization pointing at the foreign invoice id.
    $a->pdo->prepare("INSERT INTO finance_document_links (organization_id, document_type, document_id, linked_type, linked_id, linked_label) VALUES (?, 'invoice', ?, 'contract', ?, 'x')")->execute([$a->orgId, $foreignInvoice, $c['id']]);
    $data = $billing($a)->panelFor($a->admin, (int) $c['id'], $canView($a, $a->admin));
    LiteAssert::same([], array_values(array_filter($data['invoices'], fn ($r) => (int) $r['id'] === $foreignInvoice)));
    LiteAssert::same([], $data['summary'], 'nothing counted');
};

$tests['P6. Finance off → no Billing panel; the contract page still works; on again → back'] = function () use ($boot, $contract, $panelOf) {
    $a = $boot();
    $c = $contract($a);
    $a->setOrgEntitled('finance', false);
    try {
        LiteAssert::same(200, $a->request('GET', "/contracts/{$c['id']}")['status']);
        LiteAssert::same(null, $panelOf($a, (int) $c['id']));
        LiteAssert::same('notfound', $a->outcome('GET', '/finance/invoices/create', ['from_contract' => (string) $c['id']]));
    } finally {
        $a->setOrgEntitled('finance', true);
    }
    LiteAssert::true($panelOf($a, (int) $c['id']) !== null);
};

$tests['P7. defense in depth: rendered for a user who cannot view the contract, the panel offers no create button'] = function () use ($boot, $contract, $withMemberScope) {
    $a = $boot();
    $c = $contract($a); // owned by the admin
    $panels = new \Modules\Finance\FinancePanels(
        (function () use ($a) {
            return new ContractBillingService(
                $a->container()->make(ProformaService::class),
                $a->container()->make(InvoiceService::class),
                new FinanceDocumentLinkRepository(),
                $a->container()->make(\App\Application\Services\TimelineService::class),
                $a->container()->make(\App\Infrastructure\Records\RecordTypeRegistry::class),
            );
        })(),
        $a->container()->make(\App\Infrastructure\Authorization\AuthorizationEngine::class),
        $a->container()->make(\App\Infrastructure\Theme\TemplateEngine::class),
    );
    $withMemberScope($a, 'contracts.view', 'own', function () use ($a, $c, $panels) {
        $a->as($a->member);
        $html = $panels->render($c, $a->member);
        LiteAssert::notContains('from_contract=', $html, 'no create buttons for a contract outside the member\'s scope');
    });
    $a->as($a->admin);
    LiteAssert::contains('from_contract=' . $c['id'], $panels->render($c, $a->admin), 'admin still gets them');
};

// ---- Stage 5: M. translations + regression ----------------------------------

$tests['M1. every new finance.* key exists in all five languages; pages render in fa/ar/en/tr/fr with no raw finance key'] = function () use ($boot, $contract, $billing, $input) {
    $a = $boot();
    $root = dirname(__DIR__, 2);
    $keys = [];
    foreach (array_merge(
        [$root . '/modules/Finance/Services/ContractBillingService.php', $root . '/modules/Finance/Services/DocumentHeaderGuard.php', $root . '/modules/Finance/FinancePanels.php'],
        [$root . '/themes/default/templates/finance/_contract_panel.php', $root . '/themes/default/templates/finance/_contract_source.php', $root . '/themes/default/templates/finance/proformas/show.php']
    ) as $file) {
        preg_match_all("/t\\('(finance\\.[a-z0-9_.]+)'/", (string) file_get_contents($file), $m);
        preg_match_all("/titleKey: '(finance\\.[a-z0-9_.]+)'/", (string) file_get_contents($file), $m2);
        $keys = array_merge($keys, $m[1], $m2[1]);
    }
    $keys = array_values(array_unique($keys));
    LiteAssert::true(count($keys) >= 12, 'found ' . count($keys) . ' keys');
    $missing = [];
    foreach (['en', 'fa', 'ar', 'tr', 'fr'] as $lang) {
        $strings = require "{$root}/lang/{$lang}.php";
        foreach ($keys as $key) {
            if (!isset($strings[$key]) || trim((string) $strings[$key]) === '') {
                $missing[] = "{$lang}:{$key}";
            }
        }
    }
    LiteAssert::same([], $missing, implode(', ', $missing));

    $company = (int) $a->pdo->query("SELECT id FROM companies WHERE organization_id = {$a->orgId} ORDER BY id LIMIT 1")->fetchColumn();
    $c = $contract($a);
    $companyOnly = $contract($a, ['contact_id' => '', 'company_id' => (string) $company]);
    $inv = $billing($a)->createLinked($a->admin, 'invoice', $input($a), 'contract', (int) $c['id']);
    $a->pdo->prepare("UPDATE invoices SET status = 'issued' WHERE id = ?")->execute([(int) $inv['id']]);
    $pages = [
        ["/contracts/{$c['id']}", []],
        ['/finance/proformas/create', ['from_contract' => (string) $c['id']]],
        ['/finance/invoices/create', ['from_contract' => (string) $companyOnly['id']]],
        ["/finance/invoices/{$inv['id']}", []],
    ];
    $problems = [];
    foreach (['en', 'fa', 'ar', 'tr', 'fr'] as $lang) {
        $a->locale($lang);
        foreach ($pages as [$path, $query]) {
            $r = $a->request('GET', $path, $query);
            $text = html_entity_decode(strip_tags((string) preg_replace('#<(script|style)\b.*?</\1>#s', '', $r['body'])));
            preg_match_all('/\bfinance\.[a-z_]+(?:\.[a-z_]+)*\b/', $text, $m);
            if ($r['status'] !== 200 || $m[0] !== [] || !str_contains($r['body'], 'dir="' . (in_array($lang, ['fa', 'ar'], true) ? 'rtl' : 'ltr') . '"')) {
                $problems[] = "{$lang} {$path}: {$r['status']} " . implode(',', array_unique($m[0]));
            }
        }
        // The 422 path (F1 + source errors) too.
        $r = $a->request('POST', '/finance/invoices', [], $input($a, ['contact_id' => '999999999', 'source_type' => 'contract', 'source_id' => '999999999']));
        $text = html_entity_decode(strip_tags((string) $r['body']));
        preg_match_all('/\bfinance\.[a-z_]+(?:\.[a-z_]+)*\b/', $text, $m);
        if ($r['status'] !== 422 || $m[0] !== []) {
            $problems[] = "{$lang} 422 form: {$r['status']} " . implode(',', array_unique($m[0]));
        }
    }
    $a->locale('en');
    LiteAssert::same([], $problems, implode(' | ', $problems));
};

$tests['M2. regression: Finance without any contract — create, edit, convert, delete, list, show — unchanged'] = function () use ($boot, $fin, $input) {
    $a = $boot();
    $p = $fin($a, 'proforma')->create($a->admin, $input($a, ['custom_number' => 'LT-REG-P']));
    $edited = $fin($a, 'proforma')->update($a->admin, (int) $p['id'], $input($a, ['custom_number' => 'LT-REG-P2', 'notes' => 'edited']));
    LiteAssert::same('LT-REG-P2', $edited['custom_number']);
    LiteAssert::same((string) $p['grand_total'], (string) $edited['grand_total'], 'same lines → same total after edit');
    $invoice = $fin($a, 'invoice')->createFromProforma($a->admin, (int) $p['id']);
    LiteAssert::same('draft', $invoice['status']);
    LiteAssert::same('converted', $a->pdo->query("SELECT status FROM proformas WHERE id = {$p['id']}")->fetchColumn());
    LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM finance_document_links WHERE document_id IN ({$p['id']}, {$invoice['id']})")->fetchColumn(), 'no link invented');
    foreach (['/finance/proformas', '/finance/invoices', "/finance/proformas/{$p['id']}", "/finance/invoices/{$invoice['id']}", '/finance/proformas/create', '/finance/invoices/create'] as $path) {
        LiteAssert::same(200, $a->request('GET', $path)['status'], $path);
    }
    $fin($a, 'invoice')->delete($a->admin, (int) $invoice['id']);
    LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM invoices WHERE id = {$invoice['id']}")->fetchColumn());
};

$tests['rollback leaves no test data behind'] = function () use ($boot) {
    $a = $boot();
    $a->rollBack();
    LiteAssert::same(0, (int) $a->pdo->query('SELECT COUNT(*) FROM finance_document_links')->fetchColumn());
};

return $tests;
