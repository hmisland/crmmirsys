<?php

declare(strict_types=1);

/**
 * Help center categories (migrations 0091, 0092: managed by the Super
 * Admin), category filter, FAQ, pagination and the titles-only list view. One rolled-back transaction;
 * skipped until 0091 is applied.
 */

require_once __DIR__ . '/Support/LocalApp.php';

use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\UserRepository;
use Tests\Lite\Support\LocalApp;

$h = new \stdClass();
$h->app = null;
$boot = function () use ($h): LocalApp {
    if ($h->app !== null) {
        return $h->app;
    }
    $a = LocalApp::boot();
    if ((int) $a->pdo->query("SELECT COUNT(*) FROM migrations WHERE migration = '0092_create_help_categories_tables'")->fetchColumn() === 0) {
        $a->rollBack();
        throw new LiteSkip('core migrations 0091/0092 (help categories) not applied to crm_local yet');
    }
    $a->as($a->admin);
    $a->locale('en');
    $h->app = $a;
    $superId = $a->pdo->query("SELECT id FROM users WHERE is_super_admin = 1 AND status = 'active' ORDER BY id LIMIT 1")->fetchColumn();
    $h->super = $superId !== false ? (new UserRepository())->findById((int) $superId) : null;

    return $a;
};
$cards = fn (string $body): int => substr_count($body, 'class="card help-card"');
$slugsIn = function (string $body): array {
    preg_match_all('#href="/help/([a-z0-9-]+)"#', $body, $m);

    return array_values(array_unique($m[1]));
};
$categoriesOf = fn (LocalApp $a, string $slug): array => $a->pdo->query(
    'SELECT c.category FROM help_article_categories c JOIN help_articles h ON h.id = c.article_id WHERE h.slug = ' . $a->pdo->quote($slug) . ' ORDER BY c.category'
)->fetchAll(\PDO::FETCH_COLUMN);

return [
    'HC1 the seeded guides are filed under their modules; one guide can be in several categories' => function () use ($boot, $categoriesOf) {
        $a = $boot();
        LiteAssert::same(0, (int) $a->pdo->query('SELECT COUNT(*) FROM help_articles a WHERE NOT EXISTS (SELECT 1 FROM help_article_categories c WHERE c.article_id = a.id)')->fetchColumn(), 'every seeded guide has a category');
        LiteAssert::same(['contracts', 'projects', 'sales'], $categoriesOf($a, 'deal-contract-sales-project'));
        LiteAssert::same(['marketing', 'woocommerce'], $categoriesOf($a, 'segments-shop-purchases'));
        LiteAssert::same(['finance'], $categoriesOf($a, 'finance-reports'));
        LiteAssert::same(['customer_satisfaction'], $categoriesOf($a, 'cx-test-invitation'));
        LiteAssert::same(['platform'], $categoriesOf($a, 'modules-catalog'));
        LiteAssert::same([], array_diff(array_unique($a->pdo->query('SELECT category FROM help_article_categories')->fetchAll(\PDO::FETCH_COLUMN)), $a->pdo->query('SELECT `key` FROM help_categories')->fetchAll(\PDO::FETCH_COLUMN)), 'only existing categories');
    },

    'HC2 filter by category: only that category\'s guides; a guide in two categories shows in both' => function () use ($boot, $slugsIn) {
        $a = $boot();
        $finance = $slugsIn($a->request('GET', '/help', ['category' => 'finance', 'per_page' => '100'])['body']);
        LiteAssert::true(count($finance) === 12, 'finance guides: ' . count($finance));
        LiteAssert::same([], array_filter($finance, fn ($s) => !str_starts_with($s, 'finance-')));
        $shop = $slugsIn($a->request('GET', '/help', ['category' => 'woocommerce', 'per_page' => '100'])['body']);
        LiteAssert::true(in_array('segments-shop-purchases', $shop, true) && in_array('woocommerce-connect-store', $shop, true));
        LiteAssert::true(in_array('segments-shop-purchases', $slugsIn($a->request('GET', '/help', ['category' => 'marketing', 'per_page' => '100'])['body']), true));
        $search = $slugsIn($a->request('GET', '/help', ['category' => 'finance', 'q' => 'Installments', 'per_page' => '100'])['body']);
        LiteAssert::same(['finance-installments'], $search, 'search inside a category');
        // An unknown category is ignored (everything is listed).
        LiteAssert::true(count($slugsIn($a->request('GET', '/help', ['category' => 'nope', 'per_page' => '100'])['body'])) > 40);
    },

    'HC3 the filter lists categories with their counts; FAQ is always there, even while empty' => function () use ($boot) {
        $a = $boot();
        $body = html_entity_decode($a->request('GET', '/help')['body'], ENT_QUOTES);
        LiteAssert::contains('<option value="faq">Frequently asked questions (0)</option>', $body);
        LiteAssert::contains('<option value="finance">Finance (12)</option>', $body);
        LiteAssert::contains('<option value="woocommerce">WooCommerce integration (6)</option>', $body);
        LiteAssert::notContains('value="wallet"', $body, 'a category without guides is not offered');
        $faq = $a->request('GET', '/help', ['category' => 'faq'])['body'];
        LiteAssert::contains('No articles in this category yet.', $faq);
        LiteAssert::contains('<option value="faq" selected>', $faq);
        $a->locale('fa');
        LiteAssert::contains('سوالات متداول', $a->request('GET', '/help')['body']);
        $a->locale('en');
    },

    'HC4 pagination: 25 guides per page by default, the rest on the next pages' => function () use ($boot, $cards) {
        $a = $boot();
        $total = (int) $a->pdo->query('SELECT COUNT(*) FROM help_articles a WHERE a.is_published = 1 AND EXISTS (SELECT 1 FROM help_article_translations t WHERE t.article_id = a.id AND t.locale = \'en\')')->fetchColumn();
        LiteAssert::true($total > 25, 'enough guides to page: ' . $total);
        $first = $a->request('GET', '/help')['body'];
        LiteAssert::same(25, $cards($first));
        LiteAssert::contains('class="pagination-bar"', $first);
        LiteAssert::same($total - 25 > 25 ? 25 : $total - 25, $cards($a->request('GET', '/help', ['page' => '2'])['body']));
        LiteAssert::same($total, $cards($a->request('GET', '/help', ['per_page' => '100'])['body']));
    },

    'HC5 list view: titles only, filters kept; cards stay the default' => function () use ($boot, $cards) {
        $a = $boot();
        $grid = $a->request('GET', '/help', ['category' => 'finance'])['body'];
        LiteAssert::contains('class="help-grid"', $grid);
        LiteAssert::contains('help-card__summary', $grid);
        LiteAssert::contains('href="/help?category=finance&amp;view=list"', $grid, 'toggle keeps the category');
        $list = $a->request('GET', '/help', ['category' => 'finance', 'view' => 'list'])['body'];
        LiteAssert::contains('class="help-list"', $list);
        LiteAssert::same(0, $cards($list));
        LiteAssert::notContains('help-card__summary', $list, 'no summaries in the list');
        LiteAssert::same(12, substr_count($list, '<li>' . "\n" . '                    <a href="/help/finance-'));
        LiteAssert::contains('<input type="hidden" name="view" value="list">', $list, 'filtering keeps the list view');
        LiteAssert::contains('href="/help?category=finance"', $list, 'back to cards keeps the category');
    },

    'HC6 Super Admin files an article under several categories; unknown keys are dropped' => function () use ($boot, $categoriesOf) {
        $a = $boot();
        if ($h_super = ($a->pdo->query("SELECT id FROM users WHERE is_super_admin = 1 AND status = 'active' ORDER BY id LIMIT 1")->fetchColumn())) {
            $super = (new UserRepository())->findById((int) $h_super);
        } else {
            throw new LiteSkip('needs an active Super Admin');
        }
        $a->as($super);
        OrganizationContext::reset();
        LiteAssert::contains('name="categories[]" value="faq"', $a->request('GET', '/admin/help/create')['body']);
        $article = fn (array $categories) => ['slug' => 'lite-faq-guide', 'sort_order' => '5', 'is_published' => '1', 'categories' => $categories,
            'tr' => ['en' => ['title' => 'How do I reset my password?', 'summary' => 'A frequent question', 'body' => '<p>Use “Forgot password”.</p>']]];
        $res = $a->request('POST', '/admin/help', [], $article(['faq', 'general', 'not-a-category']));
        LiteAssert::same('/help/lite-faq-guide', $res['location']);
        LiteAssert::same(['faq', 'general'], $categoriesOf($a, 'lite-faq-guide'));
        $id = (int) $a->pdo->query("SELECT id FROM help_articles WHERE slug = 'lite-faq-guide'")->fetchColumn();
        $edit = $a->request('GET', '/admin/help/' . $id . '/edit')['body'];
        LiteAssert::contains('value="faq" checked', $edit);
        LiteAssert::notContains('value="finance" checked', $edit);
        $a->request('POST', '/admin/help/' . $id, [], $article(['finance']));
        LiteAssert::same(['finance'], $categoriesOf($a, 'lite-faq-guide'));
        $a->request('POST', '/admin/help/' . $id, [], $article(['faq']));

        $a->as($a->admin);
        OrganizationContext::set($a->orgId);
        $faq = $a->request('GET', '/help', ['category' => 'faq'])['body'];
        LiteAssert::contains('href="/help/lite-faq-guide"', $faq);
        LiteAssert::contains('Frequently asked questions (1)', html_entity_decode($faq, ENT_QUOTES));
        LiteAssert::contains('class="help-card__categories"', $faq, 'categories shown on the card');
        $a->as($super);
        OrganizationContext::reset();
        $a->request('POST', '/admin/help/' . $id . '/delete');
        LiteAssert::same([], $categoriesOf($a, 'lite-faq-guide'), 'categories removed with the article');
        $a->as($a->admin);
        OrganizationContext::set($a->orgId);
    },

    'HC7 the 20 seeded categories have a name in every language; FAQ is listed while empty' => function () use ($boot) {
        $a = $boot();
        $rows = $a->pdo->query("SELECT c.`key`, c.always_listed, COUNT(t.locale) AS names FROM help_categories c LEFT JOIN help_category_translations t ON t.category_id = c.id GROUP BY c.id")->fetchAll(\PDO::FETCH_ASSOC);
        $byKey = array_column($rows, null, 'key');
        foreach (['faq', 'general', 'platform', 'sales', 'woocommerce', 'marketing', 'customer_satisfaction', 'finance', 'knowledge'] as $key) {
            LiteAssert::same(5, (int) ($byKey[$key]['names'] ?? 0), "{$key} has 5 names");
        }
        LiteAssert::same(1, (int) $byKey['faq']['always_listed']);
        LiteAssert::same(0, (int) $byKey['finance']['always_listed']);
        $fa = (string) $a->pdo->query("SELECT t.name FROM help_category_translations t JOIN help_categories c ON c.id = t.category_id WHERE c.`key` = 'faq' AND t.locale = 'fa'")->fetchColumn();
        LiteAssert::same('سوالات متداول', $fa);
        $root = dirname(__DIR__, 2) . '/lang/';
        $en = require $root . 'en.php';
        $keys = array_values(array_filter(array_keys($en), fn ($k) => str_starts_with($k, 'help.categories_admin.')));
        LiteAssert::true(count($keys) >= 20);
        foreach (['fa', 'ar', 'tr', 'fr'] as $lang) {
            $lines = require $root . $lang . '.php';
            LiteAssert::same([], array_values(array_diff($keys, array_keys($lines))), "{$lang} has every category admin key");
        }
    },

    'HC8 Super Admin manages categories: create, use, filter, rename key, delete (articles stay)' => function () use ($boot, $categoriesOf) {
        $a = $boot();
        $superId = $a->pdo->query("SELECT id FROM users WHERE is_super_admin = 1 AND status = 'active' ORDER BY id LIMIT 1")->fetchColumn();
        if ($superId === false) {
            throw new LiteSkip('needs an active Super Admin');
        }
        $super = (new UserRepository())->findById((int) $superId);
        $asSuper = function () use ($a, $super): void {
            $a->as($super);
            OrganizationContext::reset(); // a real Super Admin request has no organization
        };
        $asAdmin = function () use ($a): void {
            $a->as($a->admin);
            OrganizationContext::set($a->orgId);
        };

        // Organization users cannot manage categories.
        $asAdmin();
        LiteAssert::same('forbidden', $a->outcome('GET', '/admin/help-categories'));
        LiteAssert::same('forbidden', $a->outcome('POST', '/admin/help-categories', [], ['key' => 'x', 'names' => ['en' => 'X']]));
        LiteAssert::notContains('/admin/help-categories', $a->request('GET', '/help')['body']);

        $asSuper();
        LiteAssert::contains('href="/admin/help-categories"', $a->request('GET', '/help')['body']);
        $list = $a->request('GET', '/admin/help-categories')['body'];
        LiteAssert::contains('<code>faq</code>', $list);
        // Validation: key format, duplicate key, English name required.
        LiteAssert::contains('The key may contain', $a->request('POST', '/admin/help-categories', [], ['key' => 'Getting Started', 'names' => ['en' => 'Getting started']])['body']);
        LiteAssert::contains('Another category already uses this key.', $a->request('POST', '/admin/help-categories', [], ['key' => 'faq', 'names' => ['en' => 'Dup']])['body']);
        LiteAssert::contains('Enter the English name.', $a->request('POST', '/admin/help-categories', [], ['key' => 'getting-started', 'names' => ['fa' => 'شروع کار']])['body']);
        LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM help_categories WHERE `key` IN ('getting-started', 'Getting Started')")->fetchColumn());

        $res = $a->request('POST', '/admin/help-categories', [], ['key' => 'getting-started', 'sort_order' => '15', 'names' => ['en' => 'Getting started', 'fa' => 'شروع کار']]);
        LiteAssert::same('/admin/help-categories', $res['location']);
        $catId = (int) $a->pdo->query("SELECT id FROM help_categories WHERE `key` = 'getting-started'")->fetchColumn();
        LiteAssert::true($catId > 0);
        LiteAssert::contains('value="getting-started"', $a->request('GET', '/admin/help/create')['body'], 'offered in the article form');

        // While empty it is not offered to readers; "always shown" changes that.
        $asAdmin();
        LiteAssert::notContains('value="getting-started"', $a->request('GET', '/help')['body']);
        $asSuper();
        $a->request('POST', '/admin/help-categories/' . $catId, [], ['key' => 'getting-started', 'sort_order' => '15', 'always_listed' => '1', 'names' => ['en' => 'Getting started', 'fa' => 'شروع کار']]);
        $asAdmin();
        LiteAssert::contains('<option value="getting-started">Getting started (0)</option>', html_entity_decode($a->request('GET', '/help')['body'], ENT_QUOTES));
        $a->locale('fa');
        LiteAssert::contains('شروع کار', $a->request('GET', '/help')['body'], 'Persian name');
        $a->locale('tr');
        LiteAssert::contains('Getting started', $a->request('GET', '/help')['body'], 'falls back to English');
        $a->locale('en');

        // An article in it (and in FAQ): filter finds it.
        $asSuper();
        $a->request('POST', '/admin/help', [], ['slug' => 'lite-first-steps', 'sort_order' => '1', 'is_published' => '1', 'categories' => ['getting-started', 'faq'],
            'tr' => ['en' => ['title' => 'First steps', 'summary' => '', 'body' => '<p>Start here.</p>']]]);
        LiteAssert::same(['faq', 'getting-started'], $categoriesOf($a, 'lite-first-steps'));
        $asAdmin();
        LiteAssert::contains('href="/help/lite-first-steps"', $a->request('GET', '/help', ['category' => 'getting-started'])['body']);

        // Renaming the key follows through to the article; deleting keeps the article.
        $asSuper();
        $a->request('POST', '/admin/help-categories/' . $catId, [], ['key' => 'first-steps', 'sort_order' => '15', 'names' => ['en' => 'First steps']]);
        LiteAssert::same(['faq', 'first-steps'], $categoriesOf($a, 'lite-first-steps'));
        $a->request('POST', '/admin/help-categories/' . $catId . '/delete');
        LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM help_categories WHERE id = {$catId}")->fetchColumn());
        LiteAssert::same(['faq'], $categoriesOf($a, 'lite-first-steps'), 'article stays, loses only that category');
        LiteAssert::same(1, (int) $a->pdo->query("SELECT COUNT(*) FROM help_articles WHERE slug = 'lite-first-steps'")->fetchColumn());
        LiteAssert::same('notfound', $a->outcome('GET', '/admin/help-categories/' . $catId . '/edit'));
        $asAdmin();
        LiteAssert::notContains('value="first-steps"', $a->request('GET', '/help')['body']);
        // A category key that no longer exists is ignored by the filter.
        LiteAssert::contains('href="/help/lite-first-steps"', $a->request('GET', '/help', ['category' => 'first-steps', 'per_page' => '100'])['body']);
    },

    'cleanup: roll back everything' => function () use ($h) {
        if ($h->app !== null) {
            $h->app->rollBack();
        }
        LiteAssert::true(true);
    },
];
