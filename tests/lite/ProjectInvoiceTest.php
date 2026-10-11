<?php

declare(strict_types=1);

/**
 * Stage 3-C — "New invoice" from a project (C1) and attach-list order (C3)
 * (docs/project-finance-design.md).
 *
 * C1: a DRAFT invoice created through the normal invoice form, attributed to
 * the project in the same transaction; needs invoices.create + projects.edit on
 * an open (not completed / cancelled) project the user can see; the source is
 * re-read on the server; project-side timeline without invoice details.
 * C3: unattributed invoices of the project's contact first, then of its
 * company's contacts, then the rest — order only.
 * Atomicity under a real failure is checked by
 * tests/concurrency/project-invoice-atomic-probe.php (this suite runs inside one
 * outer transaction). crm_local only, rolled back.
 */

require_once __DIR__ . '/Support/LocalApp.php';
require_once __DIR__ . '/Support/ProjectsLite.php';

use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\ValidationException;
use Modules\Finance\FinanceDocumentLinkRepository;
use Modules\Finance\InvoiceRepository;
use Modules\Finance\ProjectRevenueRepository;
use Modules\Finance\Services\InvoiceService;
use Modules\Finance\Services\ProjectInvoiceService;
use Modules\Finance\Services\ProjectRevenueService;
use Tests\Lite\Support\ProjectsLite;

$projectsBoot = ProjectsLite::booter(true);
$h = new \stdClass();
$h->ready = false;
$boot = function () use ($projectsBoot, $h) {
    $a = $projectsBoot();
    if (!$h->ready) {
        $finance = (int) $a->pdo->query("SELECT id FROM modules WHERE `key` = 'finance'")->fetchColumn();
        $a->pdo->prepare("UPDATE modules SET status = 'active' WHERE id = ?")->execute([$finance]);
        $a->pdo->prepare('DELETE FROM organization_modules WHERE organization_id = ? AND module_id = ?')->execute([$a->orgId, $finance]);
        $a->pdo->prepare('INSERT INTO organization_modules (organization_id, module_id, enabled) VALUES (?, ?, 1)')->execute([$a->orgId, $finance]);
        $a->as($a->admin);
        $a->locale('en');
        $h->cur = (int) $a->pdo->query('SELECT id FROM currencies WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
        $h->ready = true;
    }

    return $a;
};
$k = fn ($a) => $a->container();
$engine = fn ($a) => $k($a)->make(\App\Infrastructure\Authorization\AuthorizationEngine::class);
$revenue = fn ($a): ProjectRevenueService => new ProjectRevenueService(new ProjectRevenueRepository(), new FinanceDocumentLinkRepository(), new InvoiceRepository(),
    $engine($a), $k($a)->make(\App\Application\Services\TimelineService::class), $k($a)->make(\App\Infrastructure\Records\RecordTypeRegistry::class));
$svc = fn ($a): ProjectInvoiceService => new ProjectInvoiceService($k($a)->make(InvoiceService::class), new FinanceDocumentLinkRepository(), $revenue($a),
    $engine($a), $k($a)->make(\App\Application\Services\TimelineService::class));
$contact = function ($a, ?int $companyId = null): int {
    $a->pdo->prepare('INSERT INTO contacts (organization_id, first_name, last_name, company_id) VALUES (?, ?, ?, ?)')
        ->execute([$a->orgId, 'LiteTest', 'S3C ' . bin2hex(random_bytes(2)), $companyId]);

    return (int) $a->pdo->lastInsertId();
};
$company = function ($a): int {
    $a->pdo->prepare('INSERT INTO companies (organization_id, name) VALUES (?, ?)')->execute([$a->orgId, 'LiteTest S3C company ' . bin2hex(random_bytes(2))]);

    return (int) $a->pdo->lastInsertId();
};
$project = function ($a, string $status = 'active', ?array $owner = null, ?int $contactId = null, ?int $companyId = null): int {
    $a->as($a->admin);
    $id = (int) ProjectsLite::service($a)->create($a->admin, ['name' => 'LiteTest S3C project ' . bin2hex(random_bytes(2))])['id'];
    $a->pdo->prepare('UPDATE projects SET status = ?, owner_id = ?, contact_id = ?, company_id = ? WHERE id = ?')
        ->execute([$status, (int) ($owner ?? $a->admin)['id'], $contactId, $companyId, $id]);

    return $id;
};
$input = fn ($a, int $projectId, string $amount = '1234', array $extra = []) => $extra + [
    'currency_id' => (string) (int) $a->pdo->query('SELECT id FROM currencies WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn(),
    'pricing_mode' => 'tax_exclusive', 'issue_date' => date('Y-m-d'), 'tax_profile_id' => '',
    'items' => [['description' => 'LiteTest S3C line', 'quantity' => '1', 'unit_price' => $amount, 'discount_type' => 'none', 'discount_value' => '0']],
    'source_type' => 'project', 'source_id' => (string) $projectId,
];
$userWith = function ($a, array $permissions): array {
    $a->pdo->prepare('INSERT INTO roles (organization_id, `key`, name, is_system) VALUES (?, ?, ?, 0)')
        ->execute([$a->orgId, 'litetest_s3c_' . bin2hex(random_bytes(4)), 'LiteTest S3C']);
    $roleId = (int) $a->pdo->lastInsertId();
    foreach ($permissions as $key => $scope) {
        $a->pdo->prepare('INSERT INTO role_permissions (role_id, permission_key, scope) VALUES (?, ?, ?)')->execute([$roleId, $key, $scope]);
    }

    return array_merge($a->member, ['role_id' => $roleId]);
};
$creator = ['projects.view' => 'organization', 'projects.edit' => 'organization', 'invoices.view' => 'organization', 'invoices.create' => 'organization'];
$as = function ($a, array $user, callable $fn) {
    $a->as($user);
    try {
        return $fn();
    } finally {
        $a->as($a->admin);
    }
};
$invoiceCount = fn ($a): int => (int) $a->pdo->query('SELECT COUNT(*) FROM invoices')->fetchColumn();
$linkCount = fn ($a): int => (int) $a->pdo->query('SELECT COUNT(*) FROM finance_document_links')->fetchColumn();
$projectOf = fn ($a, int $invoiceId) => $a->pdo->query("SELECT linked_type, linked_id FROM finance_document_links WHERE document_type = 'invoice' AND document_id = {$invoiceId} ORDER BY linked_type")->fetchAll(\PDO::FETCH_KEY_PAIR);
$en = require dirname(__DIR__, 2) . '/lang/en.php';

$tests = [];

$tests['1. prefilled form: project source fields, the project\'s contact, a line named after the project, the notice'] = function () use ($boot, $contact, $project, $en) {
    $a = $boot();
    $c = $contact($a);
    $p = $project($a, 'active', null, $c);
    $name = (string) $a->pdo->query("SELECT name FROM projects WHERE id = {$p}")->fetchColumn();
    $r = $a->request('GET', '/finance/invoices/create', ['from_project' => (string) $p]);
    LiteAssert::same(200, $r['status']);
    LiteAssert::contains('name="source_type" value="project"', $r['body']);
    LiteAssert::contains('name="source_id" value="' . $p . '"', $r['body']);
    LiteAssert::true((bool) preg_match('#<option value="' . $c . '"[^>]*selected#', $r['body']), 'project contact preselected');
    LiteAssert::contains(htmlspecialchars($name, ENT_QUOTES), $r['body'], 'project named in the line / notice');
    LiteAssert::contains(htmlspecialchars($en['finance.project.created_from'], ENT_QUOTES), $r['body']);
};

$tests['2. POST creates ONE draft invoice attributed to the project (no other link), same totals as a plain invoice; safe timeline'] = function () use ($boot, $project, $input, $projectOf, $k) {
    $a = $boot();
    $p = $project($a);
    $r = $a->request('POST', '/finance/invoices', [], $input($a, $p, '1234'));
    LiteAssert::same(302, $r['status']);
    $id = (int) basename((string) $r['location']);
    $row = $a->pdo->query("SELECT * FROM invoices WHERE id = {$id}")->fetch(\PDO::FETCH_ASSOC);
    LiteAssert::same('draft', $row['status']);
    LiteAssert::same(['project' => (string) $p], array_map('strval', $projectOf($a, $id)), 'exactly the project link');
    $plain = $k($a)->make(InvoiceService::class)->create($a->admin, array_diff_key($input($a, $p, '1234'), ['source_type' => 1, 'source_id' => 1]));
    foreach (['subtotal', 'tax_total', 'grand_total', 'paid_total', 'currency_id', 'status'] as $f) {
        LiteAssert::same((string) $plain[$f], (string) $row[$f], "same {$f}");
    }
    $projectEvent = (string) $a->pdo->query("SELECT description FROM timeline_events WHERE subject_type = 'project' AND subject_id = {$p} AND type = 'invoice_attributed'")->fetchColumn();
    LiteAssert::same('An invoice draft was created for this project', $projectEvent);
    foreach ([(string) $id, '1,234', '1234'] as $detail) {
        LiteAssert::notContains($detail, $projectEvent, 'project-side event has no invoice detail');
    }
    LiteAssert::contains('Created from project', (string) $a->pdo->query("SELECT description FROM timeline_events WHERE subject_type = 'invoice' AND subject_id = {$id}")->fetchColumn());
};

$tests['3. the new invoice is a draft: listed on the project, no revenue until issued, then counted once'] = function () use ($boot, $project, $svc, $input, $revenue, $engine, $h) {
    $a = $boot();
    $p = $project($a);
    $inv = $svc($a)->createFromProject($a->admin, $input($a, $p, '5000'), $p);
    $panel = fn () => $revenue($a)->panelFor($a->admin, $revenue($a)->project($a->admin, $p), fn (string $key): bool => $engine($a)->can($a->admin, $key));
    LiteAssert::same([], $panel()['summary'], 'draft: no revenue');
    LiteAssert::same([(int) $inv['id']], array_column($panel()['documents'], 'id'), 'listed');
    $a->pdo->exec("UPDATE invoices SET status = 'issued' WHERE id = " . (int) $inv['id']);
    $s = $panel()['summary'];
    LiteAssert::same(1, count($s));
    LiteAssert::same(0, bccomp((string) $inv['grand_total'], $s[0]['gross'], 8), 'issued: counted once');
};

$tests['4. authorization: invoices.create (route) AND projects.edit on the project (service, record scope); nothing created when refused'] = function () use ($boot, $project, $input, $userWith, $creator, $as, $invoiceCount, $svc) {
    $a = $boot();
    $p = $project($a);
    $before = $invoiceCount($a);
    $noCreate = $userWith($a, array_diff_key($creator, ['invoices.create' => 1]));
    LiteAssert::same('forbidden', $as($a, $noCreate, fn () => $a->outcome('GET', '/finance/invoices/create', ['from_project' => (string) $p])));
    LiteAssert::same('forbidden', $as($a, $noCreate, fn () => $a->outcome('POST', '/finance/invoices', [], $input($a, $p))));
    foreach ([array_diff_key($creator, ['projects.edit' => 1]), ['projects.edit' => 'own'] + $creator] as $perms) {
        $u = $userWith($a, $perms);
        LiteAssert::same('forbidden', $as($a, $u, fn () => $a->outcome('GET', '/finance/invoices/create', ['from_project' => (string) $p])));
        LiteAssert::same('forbidden', $as($a, $u, fn () => $a->outcome('POST', '/finance/invoices', [], $input($a, $p))));
        LiteAssert::throws(AuthorizationException::class, fn () => $svc($a)->createFromProject($u, $input($a, $p), $p));
    }
    $blind = $userWith($a, array_diff_key($creator, ['projects.view' => 1]));
    LiteAssert::same('notfound', $as($a, $blind, fn () => $a->outcome('GET', '/finance/invoices/create', ['from_project' => (string) $p])));
    LiteAssert::same(422, $as($a, $blind, fn () => $a->request('POST', '/finance/invoices', [], $input($a, $p))['status']));
    LiteAssert::same($before, $invoiceCount($a), 'nothing created');
    $ok = $userWith($a, $creator);
    LiteAssert::same(302, $as($a, $ok, fn () => $a->request('POST', '/finance/invoices', [], $input($a, $p))['status']));
    LiteAssert::same($before + 1, $invoiceCount($a));
};

$tests['5. tenant: another organization\'s or an unknown project → GET 404, POST 422; nothing created'] = function () use ($boot, $input, $invoiceCount, $linkCount) {
    $a = $boot();
    $before = [$invoiceCount($a), $linkCount($a)];
    $ids = [999999999];
    if ($a->otherOrgId !== null) {
        $ids[] = ProjectsLite::foreignProject($a);
    }
    foreach ($ids as $id) {
        LiteAssert::same('notfound', $a->outcome('GET', '/finance/invoices/create', ['from_project' => (string) $id]), "GET {$id}");
        $r = $a->request('POST', '/finance/invoices', [], $input($a, $id));
        LiteAssert::same(422, $r['status'], "POST {$id}");
    }
    LiteAssert::same($before, [$invoiceCount($a), $linkCount($a)]);
};

$tests['6. project status: completed / cancelled refused (GET back to the project, POST 422); draft / planned / active / on_hold allowed'] = function () use ($boot, $project, $input, $invoiceCount, $svc, $en, $revenue) {
    $a = $boot();
    foreach (['completed', 'cancelled'] as $status) {
        $p = $project($a, $status);
        $before = $invoiceCount($a);
        $r = $a->request('GET', '/finance/invoices/create', ['from_project' => (string) $p]);
        LiteAssert::same([302, "/projects/{$p}"], [$r['status'], $r['location']], $status);
        $post = $a->request('POST', '/finance/invoices', [], $input($a, $p));
        LiteAssert::same(422, $post['status']);
        LiteAssert::contains(htmlspecialchars($en['finance.project.error.closed_project'], ENT_QUOTES), $post['body']);
        LiteAssert::same($before, $invoiceCount($a), "{$status}: nothing created");
    }
    foreach (['draft', 'planned', 'active', 'on_hold'] as $status) {
        $p = $project($a, $status);
        $svc($a)->createFromProject($a->admin, $input($a, $p), $p);
    }
    // Same closed set as attaching an existing invoice (Stage 3-B): a plain invoice can't be attached to them either.
    foreach (ProjectInvoiceService::PROJECT_STATUSES_CLOSED as $status) {
        $plain = (int) $a->container()->make(InvoiceService::class)->create($a->admin, array_diff_key($input($a, 0), ['source_type' => 1, 'source_id' => 1]))['id'];
        LiteAssert::throws(ValidationException::class, fn () => $revenue($a)->attach($a->admin, $project($a, $status), $plain), "attach to a {$status} project");
    }
};

$tests['7. manipulated source: hidden fields are re-checked — a project the user may not edit, id 0; nothing created'] = function () use ($boot, $project, $input, $userWith, $creator, $as, $invoiceCount) {
    $a = $boot();
    $mine = $userWith($a, ['projects.edit' => 'own'] + $creator);
    $ownProject = $project($a, 'active', $mine);
    $adminProject = $project($a);
    $before = $invoiceCount($a);
    // Form opened on their own project, source_id swapped for one they can't edit.
    LiteAssert::same('forbidden', $as($a, $mine, fn () => $a->outcome('POST', '/finance/invoices', [], $input($a, $ownProject, '1', ['source_id' => (string) $adminProject]))));
    LiteAssert::same(422, $a->request('POST', '/finance/invoices', [], $input($a, 0, '1', ['source_id' => '0']))['status']);
    LiteAssert::same($before, $invoiceCount($a), 'nothing created');
    LiteAssert::same(302, $as($a, $mine, fn () => $a->request('POST', '/finance/invoices', [], $input($a, $ownProject))['status']), 'their own project works');
};

$tests['8. CSRF on the create POST'] = function () use ($boot, $project, $input) {
    $a = $boot();
    $p = $project($a);
    LiteAssert::same('csrf', $a->outcome('POST', '/finance/invoices', [], $input($a, $p, '1', ['_csrf' => 'bad'])));
};

$tests['9. from_contract and from_installment unchanged: their forms, their links, never a project link'] = function () use ($boot, $h, $input, $projectOf) {
    $a = $boot();
    $c = (int) $a->container()->make(\Modules\Contracts\Services\ContractService::class)->create($a->admin, ['title' => 'LiteTest S3C contract', 'currency_id' => (string) $h->cur])['id'];
    $a->pdo->exec("UPDATE contracts SET status = 'active' WHERE id = {$c}");
    $form = $a->request('GET', '/finance/invoices/create', ['from_contract' => (string) $c]);
    LiteAssert::contains('name="source_type" value="contract"', $form['body']);
    $r = $a->request('POST', '/finance/invoices', [], $input($a, $c, '10', ['source_type' => 'contract', 'source_id' => (string) $c]));
    LiteAssert::same(['contract' => (string) $c], array_map('strval', $projectOf($a, (int) basename((string) $r['location']))));
    if ((int) $a->pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'finance_installments'")->fetchColumn() === 1) {
        $a->pdo->prepare("INSERT INTO finance_installments (organization_id, linked_type, linked_id, sequence, due_date, amount, currency_id) VALUES (?, 'contract', ?, 1, '2030-01-01', 10, ?)")->execute([$a->orgId, $c, $h->cur]);
        $i = (int) $a->pdo->lastInsertId();
        LiteAssert::contains('name="source_type" value="installment"', $a->request('GET', '/finance/invoices/create', ['from_installment' => (string) $i])['body']);
        $r = $a->request('POST', '/finance/invoices', [], $input($a, $i, '10', ['source_type' => 'installment', 'source_id' => (string) $i]));
        LiteAssert::same(['contract' => (string) $c, 'installment' => (string) $i], array_map('strval', $projectOf($a, (int) basename((string) $r['location']))));
    }
    // A plain invoice (no source) still has no link at all.
    $plain = $a->request('POST', '/finance/invoices', [], array_diff_key($input($a, 0, '10'), ['source_type' => 1, 'source_id' => 1]));
    LiteAssert::same([], $projectOf($a, (int) basename((string) $plain['location'])));
};

$tests['10. C3 order: the project\'s contact first, then its company\'s contacts, then the rest (newest first); eligibility unchanged'] = function () use ($boot, $h, $contact, $company, $project, $revenue, $engine, $k) {
    $a = $boot();
    $co = $company($a);
    $projectContact = $contact($a);
    $colleague = $contact($a, $co);
    $stranger = $contact($a);
    $p = $project($a, 'active', null, $projectContact, $co);
    $mk = function (?int $contactId, string $status = 'draft') use ($a, $h, $k): int {
        $id = (int) $k($a)->make(InvoiceService::class)->create($a->admin, ['currency_id' => (string) $h->cur, 'pricing_mode' => 'tax_exclusive', 'issue_date' => date('Y-m-d'), 'tax_profile_id' => '',
            'items' => [['description' => 'LiteTest S3C order', 'quantity' => '1', 'unit_price' => '1', 'discount_type' => 'none', 'discount_value' => '0']]])['id'];
        $a->pdo->prepare('UPDATE invoices SET contact_id = ?, status = ? WHERE id = ?')->execute([$contactId, $status, $id]);

        return $id;
    };
    $own = $mk($projectContact);              // oldest of the group, still first
    $colleagueInv = $mk($colleague, 'issued');
    $other = $mk($stranger);
    $none = $mk(null);
    $cancelled = $mk($projectContact, 'cancelled');
    $taken = $mk($projectContact);
    $revenue($a)->attach($a->admin, $project($a), $taken);
    $candidates = array_map(fn ($r) => (int) $r['id'], $revenue($a)->panelFor($a->admin, $revenue($a)->project($a->admin, $p), fn (string $key): bool => $engine($a)->can($a->admin, $key))['candidates']);
    $mine = array_values(array_filter($candidates, fn ($id) => in_array($id, [$own, $colleagueInv, $other, $none], true)));
    LiteAssert::same([$own, $colleagueInv, $none, $other], $mine, 'contact, company, then newest first');
    LiteAssert::same(0, $candidates === [] ? 1 : array_search($own, $candidates, true), 'the project contact\'s invoice heads the whole list');
    LiteAssert::false(in_array($cancelled, $candidates, true), 'cancelled still excluded');
    LiteAssert::false(in_array($taken, $candidates, true), 'attributed still excluded');
    // A project without contact / company: plain newest-first.
    $bare = $project($a);
    $plain = array_map(fn ($r) => (int) $r['id'], $revenue($a)->panelFor($a->admin, $revenue($a)->project($a->admin, $bare), fn (string $key): bool => $engine($a)->can($a->admin, $key))['candidates']);
    $sorted = $plain;
    rsort($sorted);
    LiteAssert::same($sorted, $plain);
};

$tests['11. panel: "New invoice" only for a creator on an open project'] = function () use ($boot, $project, $userWith, $creator, $as) {
    $a = $boot();
    $p = $project($a);
    $link = '/finance/invoices/create?from_project=' . $p;
    LiteAssert::contains($link, $a->request('GET', "/projects/{$p}")['body']);
    foreach ([array_diff_key($creator, ['invoices.create' => 1]), array_diff_key($creator, ['projects.edit' => 1])] as $perms) {
        LiteAssert::notContains($link, $as($a, $userWith($a, $perms), fn () => $a->request('GET', "/projects/{$p}")['body']));
    }
    $done = $project($a, 'completed');
    LiteAssert::notContains('/finance/invoices/create?from_project=' . $done, $a->request('GET', "/projects/{$done}")['body']);
};

$tests['12. five languages: new keys present; prefilled form renders without raw keys'] = function () use ($boot, $project) {
    $a = $boot();
    $p = $project($a);
    try {
        foreach (['en', 'fa', 'ar', 'tr', 'fr'] as $locale) {
            $lang = require dirname(__DIR__, 2) . "/lang/{$locale}.php";
            foreach (['finance.project.created_from', 'finance.project.error.project_unavailable'] as $key) {
                LiteAssert::true(trim((string) ($lang[$key] ?? '')) !== '', "{$locale}: {$key}");
            }
            $a->locale($locale);
            $html = $a->request('GET', '/finance/invoices/create', ['from_project' => (string) $p])['body'];
            LiteAssert::contains(htmlspecialchars($lang['finance.project.created_from'], ENT_QUOTES), $html, $locale);
            LiteAssert::notContains('finance.project.', $html, "{$locale}: raw key");
        }
    } finally {
        $a->locale('en');
    }
};

$tests['rollback leaves no test data behind'] = function () use ($boot) {
    $a = $boot();
    $a->rollBack();
    LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM projects WHERE name LIKE 'LiteTest S3C%'")->fetchColumn());
    LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM contacts WHERE first_name = 'LiteTest' AND last_name LIKE 'S3C%'")->fetchColumn());
    LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM roles WHERE `key` LIKE 'litetest_s3c_%'")->fetchColumn());
};

return $tests;
