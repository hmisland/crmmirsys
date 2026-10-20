<?php

declare(strict_types=1);

/**
 * Help center categories (migration 0091), category filter, FAQ,
 * pagination and the titles-only list view. One rolled-back transaction;
 * skipped until 0091 is applied.
 */

require_once __DIR__ . '/Support/LocalApp.php';

use App\Application\Services\HelpCategories;
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
    if ((int) $a->pdo->query("SELECT COUNT(*) FROM migrations WHERE migration = '0091_create_help_article_categories_table'")->fetchColumn() === 0) {
        $a->rollBack();
        throw new LiteSkip('core migration 0091 (help categories) not applied to crm_local yet');
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
        LiteAssert::same([], array_diff(array_unique($a->pdo->query('SELECT category FROM help_article_categories')->fetchAll(\PDO::FETCH_COLUMN)), HelpCategories::KEYS), 'only known categories');
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

    'HC7 help category keys are translated in all five languages' => function () {
        $root = dirname(__DIR__, 2) . '/lang/';
        $en = require $root . 'en.php';
        $keys = ['help.categories', 'help.categories_hint', 'help.category.faq', 'help.category.general', 'help.category.platform', 'help.category_filter', 'help.all_categories', 'help.category_empty', 'help.view_mode', 'help.view_grid', 'help.view_list'];
        foreach (HelpCategories::KEYS as $key) {
            if (!in_array($key, ['faq', 'general', 'platform'], true)) {
                $keys[] = 'catalog.module.' . $key . '.name';
            }
        }
        foreach (['en', 'fa', 'ar', 'tr', 'fr'] as $lang) {
            $lines = require $root . $lang . '.php';
            LiteAssert::same([], array_values(array_diff($keys, array_keys($lines))), "{$lang} has every help category label");
        }
    },

    'cleanup: roll back everything' => function () use ($h) {
        if ($h->app !== null) {
            $h->app->rollBack();
        }
        LiteAssert::true(true);
    },
];
