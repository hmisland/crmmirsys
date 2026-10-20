<?php

declare(strict_types=1);

/**
 * Help guides of core migration 0089: WooCommerce, shop-purchase segments,
 * scheduled campaigns, transactional messages, Customer satisfaction and
 * Modules & Features — 13 articles, each in all five languages. One
 * rolled-back transaction; skipped until 0089 is applied.
 */

require_once __DIR__ . '/Support/LocalApp.php';

use App\Infrastructure\Security\HtmlSanitizer;
use Tests\Lite\Support\LocalApp;

const INT_HELP = [
    'woocommerce-connect-store', 'woocommerce-order-sync', 'woocommerce-contact-sync', 'woocommerce-import-older-orders',
    'woocommerce-contact-orders', 'segments-shop-purchases', 'marketing-scheduled-campaigns', 'marketing-transactional-messages',
    'cx-getting-started', 'cx-test-invitation', 'cx-results-follow-ups', 'modules-catalog', 'modules-activation-request',
];

$h = new \stdClass();
$h->app = null;
$boot = function () use ($h): LocalApp {
    if ($h->app !== null) {
        return $h->app;
    }
    $a = LocalApp::boot();
    if ((int) $a->pdo->query("SELECT COUNT(*) FROM migrations WHERE migration = '0089_seed_integration_cx_module_help_articles'")->fetchColumn() === 0) {
        $a->rollBack();
        throw new LiteSkip('core migration 0089 (integration, CX and module help guides) not applied to crm_local yet');
    }
    foreach (['woocommerce', 'customer_satisfaction', 'marketing'] as $key) {
        $a->pdo->exec("INSERT IGNORE INTO organization_modules (organization_id, module_id, enabled) SELECT {$a->orgId}, id, 1 FROM modules WHERE `key` = '{$key}'");
        $a->setOrgEntitled($key, true);
    }
    $a->as($a->admin);
    $a->locale('en');
    $h->app = $a;

    return $a;
};
$root = dirname(__DIR__, 2);

return [
    'IH1 seed: 13 published guides, each in all five languages' => function () use ($boot) {
        $a = $boot();
        $rows = $a->pdo->query(
            "SELECT a.slug, GROUP_CONCAT(t.locale ORDER BY t.locale) FROM help_articles a
             JOIN help_article_translations t ON t.article_id = a.id WHERE a.sort_order BETWEEN 100 AND 121 AND a.is_published = 1 GROUP BY a.id"
        )->fetchAll(\PDO::FETCH_KEY_PAIR);
        LiteAssert::same(13, count($rows));
        foreach (INT_HELP as $slug) {
            LiteAssert::same('ar,en,fa,fr,tr', $rows[$slug] ?? null, $slug);
        }
    },

    'IH2 every guide reads in each language (page + popup), right direction, no raw keys' => function () use ($boot, $root) {
        $a = $boot();
        foreach (['en' => 'ltr', 'fa' => 'rtl', 'ar' => 'rtl', 'tr' => 'ltr', 'fr' => 'ltr'] as $locale => $dir) {
            $a->locale($locale);
            $list = $a->request('GET', '/help')['body'];
            LiteAssert::contains('dir="' . $dir . '"', $list);
            foreach (INT_HELP as $slug) {
                $meta = require $root . "/database/help-content/{$slug}/meta.php";
                LiteAssert::contains('href="/help/' . $slug . '"', $list, "{$slug} listed ({$locale})");
                $page = html_entity_decode($a->request('GET', '/help/' . $slug)['body'], ENT_QUOTES);
                LiteAssert::contains($meta[$locale]['title'], $page, "{$slug} title ({$locale})");
                LiteAssert::same(0, preg_match('/\b(?:woocommerce|integrations|cx|catalog|marketing)\.[a-z_]{2,}/', strip_tags($page)), "raw key on {$slug} ({$locale})");
                LiteAssert::notContains('<html', $a->request('GET', '/help/' . $slug, ['fragment' => '1'])['body']);
            }
        }
        $a->locale('en');
    },

    'IH3 the content is safe and keeps the English structure in every language' => function () use ($boot, $root) {
        $a = $boot();
        $tags = fn (string $html): int => preg_match_all('/<([a-z0-9]+)[\s>]/i', $html);
        foreach (INT_HELP as $slug) {
            $en = (string) file_get_contents($root . "/database/help-content/{$slug}/en.html");
            foreach (['en', 'fa', 'ar', 'tr', 'fr'] as $locale) {
                $body = (string) file_get_contents($root . "/database/help-content/{$slug}/{$locale}.html");
                LiteAssert::same($tags($body), $tags(HtmlSanitizer::clean($body)), "{$slug}/{$locale}: no element dropped");
                LiteAssert::same(0, preg_match('/style=|<script|on[a-z]+=/i', $body), "{$slug}/{$locale}");
                LiteAssert::same($tags($en), $tags($body), "{$slug}/{$locale}: same structure as English");
            }
        }
    },

    'IH4 every new help_link() target is a published guide' => function () use ($boot, $root) {
        $a = $boot();
        $files = array_merge(
            glob($root . '/themes/default/templates/woocommerce/*.php'),
            glob($root . '/themes/default/templates/cx/*.php'),
            glob($root . '/themes/default/templates/marketing/*.php'),
            glob($root . '/themes/default/templates/marketing/*/*.php'),
            glob($root . '/themes/default/templates/org/*.php'),
            [$root . '/themes/default/templates/admin/module-requests.php']
        );
        $used = [];
        foreach ($files as $file) {
            if (preg_match_all("/help_link\('([a-z0-9-]+)'\)/", (string) file_get_contents($file), $m)) {
                $used = array_merge($used, $m[1]);
            }
        }
        $used = array_values(array_unique($used));
        foreach (INT_HELP as $slug) {
            LiteAssert::true(in_array($slug, $used, true), "{$slug} is linked from a page");
        }
        foreach ($used as $slug) {
            LiteAssert::same(1, (int) $a->pdo->query('SELECT COUNT(*) FROM help_articles WHERE is_published = 1 AND slug = ' . $a->pdo->quote($slug))->fetchColumn(), $slug);
        }
    },

    'IH5 the "?" buttons are on the pages' => function () use ($boot) {
        $a = $boot();
        $pages = [
            '/org/integrations' => ['woocommerce-connect-store'],
            '/cx' => ['cx-getting-started', 'cx-results-follow-ups'], // the test card needs a survey; IH4 covers its link
            '/marketing/transactional' => ['marketing-transactional-messages'],
            '/org/modules/catalog' => ['modules-catalog'],
            '/org/modules' => ['modules-activation-request'],
        ];
        foreach ($pages as $page => $slugs) {
            $body = $a->request('GET', $page)['body'];
            foreach ($slugs as $slug) {
                LiteAssert::contains('href="/help/' . $slug . '"', $body, "{$slug} on {$page}");
            }
        }
    },

    'IH6 the seed is idempotent and never overwrites an edited guide' => function () use ($boot, $root) {
        $a = $boot();
        $a->pdo->exec("UPDATE help_article_translations t JOIN help_articles h ON h.id = t.article_id SET t.title = 'Edited by Super Admin' WHERE h.slug = 'cx-getting-started' AND t.locale = 'en'");
        $migration = require $root . '/database/migrations/0089_seed_integration_cx_module_help_articles.php';
        $seed = \Closure::bind(fn (\PDO $pdo, string $slug, int $order) => $this->seed($pdo, $slug, $order), $migration, get_class($migration));
        foreach (INT_HELP as $slug) {
            $seed($a->pdo, $slug, 199);
        }
        LiteAssert::same(0, (int) $a->pdo->query('SELECT COUNT(*) FROM help_articles WHERE sort_order = 199')->fetchColumn(), 'nothing re-inserted');
        LiteAssert::same('Edited by Super Admin', $a->pdo->query("SELECT t.title FROM help_article_translations t JOIN help_articles h ON h.id = t.article_id WHERE h.slug = 'cx-getting-started' AND t.locale = 'en'")->fetchColumn());
    },

    'cleanup: roll back everything' => function () use ($h) {
        if ($h->app !== null) {
            $h->app->rollBack();
        }
        LiteAssert::true(true);
    },
];
