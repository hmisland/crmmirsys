<?php

declare(strict_types=1);

/**
 * Finance help guides (core migration 0078): 12 articles × 5 languages,
 * seeded from database/help-content/finance-* and linked from the Finance
 * pages with help_link().
 *
 * In-suite (one rolled-back transaction): the seed, every article readable
 * in every language (full page and popup fragment), the content survives
 * the sanitizer, every help_link() slug in the Finance templates exists,
 * and the "?" buttons are on the pages. Migration idempotency (it runs its
 * own transaction) → child process. Skipped until 0078 is applied.
 */

require_once __DIR__ . '/Support/LocalApp.php';

use App\Infrastructure\Security\HtmlSanitizer;
use Tests\Lite\Support\LocalApp;

const FIN_HELP_SLUGS = [
    'finance-getting-started', 'finance-proformas-invoices', 'finance-amounts-discounts-tax', 'finance-final-rounding',
    'finance-payments-balance', 'finance-credit-notes-cancel', 'finance-installments', 'finance-expenses',
    'finance-project-finance', 'finance-periods', 'finance-reports', 'finance-permissions-audit',
];

$h = new \stdClass();
$h->app = null;
$boot = function () use ($h): LocalApp {
    if ($h->app !== null) {
        return $h->app;
    }
    $a = LocalApp::boot();
    if ((int) $a->pdo->query("SELECT COUNT(*) FROM migrations WHERE migration = '0078_seed_finance_help_articles'")->fetchColumn() === 0) {
        $a->rollBack();
        throw new LiteSkip('core migration 0078 (Finance help guides) not applied to crm_local yet');
    }
    $finance = (int) $a->pdo->query("SELECT id FROM modules WHERE `key` = 'finance'")->fetchColumn();
    if ($finance === 0) {
        $a->rollBack();
        throw new LiteSkip('Finance not installed in crm_local');
    }
    $a->pdo->prepare("UPDATE modules SET status = 'active' WHERE id = ?")->execute([$finance]);
    $a->pdo->prepare('DELETE FROM organization_modules WHERE organization_id = ? AND module_id = ?')->execute([$a->orgId, $finance]);
    $a->pdo->prepare('INSERT INTO organization_modules (organization_id, module_id, enabled) VALUES (?, ?, 1)')->execute([$a->orgId, $finance]);
    $a->as($a->admin);
    $a->locale('en');
    $h->app = $a;

    return $a;
};
$root = dirname(__DIR__, 2);

$tests = [];

$tests['FH1 seed: 12 published Finance guides, all five languages, in reading order after the first guide'] = function () use ($boot) {
    $a = $boot();
    $rows = $a->pdo->query(
        "SELECT a.slug, a.sort_order, a.is_published, GROUP_CONCAT(t.locale ORDER BY t.locale) AS locales
         FROM help_articles a JOIN help_article_translations t ON t.article_id = a.id
         WHERE a.slug LIKE 'finance-%' GROUP BY a.id ORDER BY a.sort_order"
    )->fetchAll(\PDO::FETCH_ASSOC);
    LiteAssert::same(FIN_HELP_SLUGS, array_column($rows, 'slug'));
    LiteAssert::same(range(20, 31), array_map('intval', array_column($rows, 'sort_order')));
    foreach ($rows as $r) {
        LiteAssert::same(['1', 'ar,en,fa,fr,tr'], [(string) $r['is_published'], $r['locales']], $r['slug']);
    }
    LiteAssert::same(1, (int) $a->pdo->query("SELECT COUNT(*) FROM help_articles WHERE slug = 'deal-contract-sales-project'")->fetchColumn(), 'the first guide is untouched');
};

$tests['FH2 every guide reads in every language (page + popup): title, example, finance tip, right direction, no raw keys'] = function () use ($boot, $root) {
    $a = $boot();
    foreach (['en' => 'ltr', 'fa' => 'rtl', 'ar' => 'rtl', 'tr' => 'ltr', 'fr' => 'ltr'] as $locale => $dir) {
        $a->locale($locale);
        $list = $a->request('GET', '/help')['body'];
        LiteAssert::contains('dir="' . $dir . '"', $list);
        foreach (FIN_HELP_SLUGS as $slug) {
            $meta = require $root . "/database/help-content/{$slug}/meta.php";
            LiteAssert::contains('href="/help/' . $slug . '"', $list, "{$slug} listed ({$locale})");
            $page = html_entity_decode($a->request('GET', '/help/' . $slug)['body'], ENT_QUOTES);
            LiteAssert::contains($meta[$locale]['title'], $page, "{$slug} title ({$locale})");
            LiteAssert::contains('class="help-example"', $page, "{$slug} example ({$locale})");
            LiteAssert::contains('help-callout', $page, "{$slug} finance tip ({$locale})");
            LiteAssert::notContains('finance.', strip_tags($page), "raw key on {$slug} ({$locale})");
            $fragment = $a->request('GET', '/help/' . $slug, ['fragment' => '1'])['body'];
            LiteAssert::notContains('<html', $fragment);
            LiteAssert::contains('class="help-example"', $fragment, "{$slug} popup ({$locale})");
        }
    }
    $a->locale('en');
};

$tests['FH3 the content is safe: the sanitizer keeps every element, no inline style, links only to existing guides'] = function () use ($boot) {
    $a = $boot();
    $rows = $a->pdo->query("SELECT a.slug, t.locale, t.body FROM help_articles a JOIN help_article_translations t ON t.article_id = a.id WHERE a.slug LIKE 'finance-%'")->fetchAll(\PDO::FETCH_ASSOC);
    LiteAssert::same(60, count($rows));
    $tags = fn (string $html): int => preg_match_all('/<([a-z0-9]+)[\s>]/i', $html);
    foreach ($rows as $r) {
        $clean = HtmlSanitizer::clean($r['body']);
        LiteAssert::same($tags($r['body']), $tags($clean), "{$r['slug']}/{$r['locale']}: no element dropped");
        LiteAssert::same(0, preg_match('/style=|<script|on[a-z]+=/i', $r['body']), "{$r['slug']}/{$r['locale']}");
        preg_match_all('#href="/help/([a-z0-9-]+)"#', $r['body'], $m);
        foreach ($m[1] as $target) {
            LiteAssert::true(in_array($target, FIN_HELP_SLUGS, true) || $target === 'deal-contract-sales-project', "{$r['slug']} links to {$target}");
        }
    }
};

$tests['FH4 every help_link() slug used in the Finance templates is a published guide'] = function () use ($boot, $root) {
    $a = $boot();
    $used = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/themes/default/templates/finance')) as $f) {
        if ($f->isFile() && preg_match_all("/help_link\\('([a-z0-9-]+)'\\)/", (string) file_get_contents($f->getPathname()), $m)) {
            $used = array_merge($used, $m[1]);
        }
    }
    $used = array_values(array_unique($used));
    sort($used);
    $expected = FIN_HELP_SLUGS;
    sort($expected);
    LiteAssert::same($expected, $used, 'all 12 guides are linked from Finance pages, and nothing else');
    foreach ($used as $slug) {
        LiteAssert::same(1, (int) $a->pdo->query("SELECT COUNT(*) FROM help_articles WHERE slug = '{$slug}' AND is_published = 1")->fetchColumn(), $slug);
    }
};

$tests['FH5 the "?" buttons are on the Finance pages'] = function () use ($boot) {
    $a = $boot();
    $irr = (int) $a->pdo->query("SELECT id FROM currencies WHERE code = 'IRR'")->fetchColumn();
    $a->pdo->prepare("INSERT INTO invoices (organization_id, owner_id, issue_date, currency_id, subtotal, tax_total, grand_total, paid_total, status, notes) VALUES (?, ?, CURDATE(), ?, 100, 0, 100, 0, 'issued', 'LiteTest FH')")
        ->execute([$a->orgId, (int) $a->admin['id'], $irr]);
    $invoice = (int) $a->pdo->lastInsertId();
    $a->pdo->prepare("INSERT INTO timeline_events (organization_id, subject_type, subject_id, type, description, actor_user_id) VALUES (?, 'invoice', ?, 'payment_recorded', 'LiteTest FH', ?)")
        ->execute([$a->orgId, $invoice, (int) $a->admin['id']]);
    $pages = [
        '/finance/settings' => ['finance-getting-started', 'finance-final-rounding'],
        '/finance/invoices' => ['finance-proformas-invoices'],
        '/finance/proformas' => ['finance-proformas-invoices'],
        '/finance/invoices/create' => ['finance-amounts-discounts-tax'],
        "/finance/invoices/{$invoice}" => ['finance-proformas-invoices', 'finance-amounts-discounts-tax', 'finance-payments-balance', 'finance-credit-notes-cancel', 'finance-permissions-audit'],
        '/finance/expenses' => ['finance-expenses'],
        '/finance/periods' => ['finance-periods'],
        '/finance/reports' => ['finance-reports'],
    ];
    foreach ($pages as $path => $slugs) {
        $body = $a->request('GET', $path)['body'];
        foreach ($slugs as $slug) {
            LiteAssert::contains('href="/help/' . $slug . '" class="help-link"', $body, "{$path} → {$slug}");
        }
    }
    LiteAssert::notContains("help_link('deal-contract-sales-project')", (string) file_get_contents(dirname(__DIR__, 2) . '/themes/default/templates/finance/invoices/index.php'), 'the invoice list points to the Finance guide');
};

$tests['FH6 migration 0078 is idempotent and never overwrites an edited guide (child process, restored)'] = function () use ($boot) {
    $a = $boot();
    $a->rollBack(); // release this suite's locks before the child writes
    $json = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/standalone/finance-help-child.php') . ' 2>&1');
    $r = json_decode(trim($json), true);
    LiteAssert::true(is_array($r), 'child output: ' . substr($json, 0, 300));
    if (isset($r['skip'])) {
        throw new LiteSkip($r['skip']);
    }
    LiteAssert::same([true, true, false, true], [$r['unchanged'], $r['edit_kept'], $r['in_transaction_after'], $r['restored']]);
};

$tests['rollback leaves no test data behind'] = function () use ($boot) {
    $a = $boot();
    $a->rollBack();
    LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM invoices WHERE notes = 'LiteTest FH'")->fetchColumn());
    LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM help_article_translations WHERE title LIKE 'LiteTest%'")->fetchColumn());
};

return $tests;
