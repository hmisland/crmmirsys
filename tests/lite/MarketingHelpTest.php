<?php

declare(strict_types=1);

/**
 * Marketing help guides (core migration 0082): 21 articles — 17 in all
 * five languages, the 4 Kavenegar SMS guides in Persian only. One
 * rolled-back transaction; skipped until 0082 is applied.
 */

require_once __DIR__ . '/Support/LocalApp.php';

use App\Infrastructure\Security\HtmlSanitizer;
use Tests\Lite\Support\LocalApp;

const MKT_HELP_ALL = [
    'email-getting-started', 'email-sender-signup', 'email-domain-authentication', 'email-sender-connect',
    'email-sender-free-plan', 'email-brevo-connect', 'email-inbox-placement',
    'marketing-email-basics', 'marketing-email-writing', 'marketing-unsubscribe-optout',
    'marketing-transactional-vs-marketing', 'marketing-campaign-results',
    'segments-introduction', 'segments-dynamic-builder', 'segments-static', 'segments-in-campaigns',
    'marketing-permissions-rules',
];
const MKT_HELP_FA_ONLY = ['sms-kavenegar-signup', 'sms-kavenegar-account-modes', 'sms-kavenegar-promotional-rules', 'sms-first-campaign'];

$h = new \stdClass();
$h->app = null;
$boot = function () use ($h): LocalApp {
    if ($h->app !== null) {
        return $h->app;
    }
    $a = LocalApp::boot();
    if ((int) $a->pdo->query("SELECT COUNT(*) FROM migrations WHERE migration = '0082_seed_marketing_help_articles'")->fetchColumn() === 0) {
        $a->rollBack();
        throw new LiteSkip('core migration 0082 (Marketing help guides) not applied to crm_local yet');
    }
    $a->pdo->exec("DELETE FROM modules WHERE `key` IN ('marketing', 'marketing_email')");
    foreach (['marketing', 'marketing_email'] as $key) {
        $a->pdo->prepare("INSERT INTO modules (`key`, name, version, type, is_core, status, dependencies) VALUES (?, ?, '1.0.0', 'addon', 0, 'active', '[]')")->execute([$key, $key]);
        $a->pdo->prepare('INSERT INTO organization_modules (organization_id, module_id, enabled) VALUES (?, ?, 1)')->execute([$a->orgId, (int) $a->pdo->lastInsertId()]);
    }
    $a->as($a->admin);
    $a->locale('en');
    $h->app = $a;

    return $a;
};
$root = dirname(__DIR__, 2);

return [
    'MH1 seed: 21 published guides; Kavenegar guides in Persian only, the rest in all five languages' => function () use ($boot) {
        $a = $boot();
        $rows = $a->pdo->query(
            "SELECT a.slug, GROUP_CONCAT(t.locale ORDER BY t.locale) AS locales FROM help_articles a
             JOIN help_article_translations t ON t.article_id = a.id WHERE a.sort_order BETWEEN 40 AND 80 AND a.is_published = 1 GROUP BY a.id"
        )->fetchAll(\PDO::FETCH_KEY_PAIR);
        LiteAssert::same(21, count($rows));
        foreach (MKT_HELP_ALL as $slug) {
            LiteAssert::same('ar,en,fa,fr,tr', $rows[$slug] ?? null, $slug);
        }
        foreach (MKT_HELP_FA_ONLY as $slug) {
            LiteAssert::same('fa', $rows[$slug] ?? null, $slug . ' must be Persian only');
        }
    },

    'MH2 every guide reads in each of its languages (page + popup), right direction, no raw keys' => function () use ($boot, $root) {
        $a = $boot();
        foreach (['en' => 'ltr', 'fa' => 'rtl', 'ar' => 'rtl', 'tr' => 'ltr', 'fr' => 'ltr'] as $locale => $dir) {
            $a->locale($locale);
            $list = $a->request('GET', '/help')['body'];
            LiteAssert::contains('dir="' . $dir . '"', $list);
            $slugs = $locale === 'fa' ? array_merge(MKT_HELP_ALL, MKT_HELP_FA_ONLY) : MKT_HELP_ALL;
            foreach ($slugs as $slug) {
                $meta = require $root . "/database/help-content/{$slug}/meta.php";
                LiteAssert::contains('href="/help/' . $slug . '"', $list, "{$slug} listed ({$locale})");
                $page = html_entity_decode($a->request('GET', '/help/' . $slug)['body'], ENT_QUOTES);
                LiteAssert::contains($meta[$locale]['title'], $page, "{$slug} title ({$locale})");
                LiteAssert::same(0, preg_match('/\b(?:marketing|email_providers|messaging)\.[a-z_]{2,}/', strip_tags($page)), "raw key on {$slug} ({$locale})");
                $fragment = $a->request('GET', '/help/' . $slug, ['fragment' => '1'])['body'];
                LiteAssert::notContains('<html', $fragment);
            }
        }
        $a->locale('en');
    },

    'MH3 Kavenegar guides never appear in other languages — not listed and not reachable' => function () use ($boot) {
        $a = $boot();
        foreach (['en', 'ar', 'tr', 'fr'] as $locale) {
            $a->locale($locale);
            $list = $a->request('GET', '/help')['body'];
            foreach (MKT_HELP_FA_ONLY as $slug) {
                LiteAssert::notContains('/help/' . $slug . '"', $list, "{$slug} listed in {$locale}");
                LiteAssert::same('notfound', $a->outcome('GET', '/help/' . $slug), "{$slug} reachable in {$locale}");
            }
        }
        $a->locale('fa');
        LiteAssert::same(200, $a->request('GET', '/help/sms-kavenegar-promotional-rules')['status']);
        LiteAssert::contains('لغو', $a->request('GET', '/help/sms-kavenegar-promotional-rules')['body']);
        $a->locale('en');
    },

    'MH4 the Sender free-plan guide cites the official pricing page in every language' => function () use ($boot) {
        $a = $boot();
        $rows = $a->pdo->query("SELECT t.locale, t.body FROM help_articles a JOIN help_article_translations t ON t.article_id = a.id WHERE a.slug = 'email-sender-free-plan'")->fetchAll(\PDO::FETCH_KEY_PAIR);
        LiteAssert::same(5, count($rows));
        foreach ($rows as $locale => $body) {
            LiteAssert::contains('href="https://www.sender.net/pricing/"', $body, $locale);
        }
    },

    'MH5 the content is safe: the sanitizer keeps every element, no inline style or script' => function () use ($boot) {
        $a = $boot();
        $rows = $a->pdo->query("SELECT a.slug, t.locale, t.body FROM help_articles a JOIN help_article_translations t ON t.article_id = a.id WHERE a.sort_order BETWEEN 40 AND 80")->fetchAll(\PDO::FETCH_ASSOC);
        LiteAssert::same(17 * 5 + 4, count($rows));
        $tags = fn (string $html): int => preg_match_all('/<([a-z0-9]+)[\s>]/i', $html);
        foreach ($rows as $r) {
            $clean = HtmlSanitizer::clean($r['body']);
            LiteAssert::same($tags($r['body']), $tags($clean), "{$r['slug']}/{$r['locale']}: no element dropped");
            LiteAssert::same(0, preg_match('/style=|<script|on[a-z]+=/i', $r['body']), "{$r['slug']}/{$r['locale']}");
        }
    },

    'MH6 every help_link() target in the Marketing and messaging templates is a published guide' => function () use ($boot, $root) {
        $a = $boot();
        $used = [];
        $files = array_merge(
            glob($root . '/themes/default/templates/marketing/*.php'),
            glob($root . '/themes/default/templates/marketing/*/*.php'),
            [$root . '/themes/default/templates/org/messaging.php', $root . '/themes/default/templates/org/sms.php']
        );
        foreach ($files as $file) {
            if (preg_match_all("/'((?:email|marketing|sms|segments)-[a-z0-9-]+)'/", (string) file_get_contents($file), $m)) {
                $used = array_merge($used, $m[1]);
            }
        }
        $used = array_values(array_unique($used));
        LiteAssert::true(count($used) >= 12, 'help links present');
        foreach ($used as $slug) {
            LiteAssert::same(1, (int) $a->pdo->query("SELECT COUNT(*) FROM help_articles WHERE slug = " . $a->pdo->quote($slug) . " AND is_published = 1")->fetchColumn(), $slug);
        }
    },

    'MH7 the "?" buttons are on the pages; the Kavenegar one only in Persian' => function () use ($boot) {
        $a = $boot();
        $a->locale('en');
        $pages = [
            '/org/messaging' => ['email-getting-started', 'email-sender-connect', 'email-brevo-connect'],
            '/marketing' => ['marketing-email-basics'],
            '/marketing/segments' => ['segments-introduction'],
            '/marketing/suppressions' => ['marketing-unsubscribe-optout'],
        ];
        foreach ($pages as $page => $slugs) {
            $body = $a->request('GET', $page)['body'];
            foreach ($slugs as $slug) {
                LiteAssert::contains('href="/help/' . $slug . '"', $body, "{$slug} on {$page}");
            }
        }
        LiteAssert::notContains('/help/sms-kavenegar-account-modes', $a->request('GET', '/org/messaging')['body'], 'no Persian-only link in English');
        $a->locale('fa');
        LiteAssert::contains('href="/help/sms-kavenegar-account-modes"', $a->request('GET', '/org/messaging')['body']);
        $a->locale('en');
    },

    'MH8 the seed is idempotent and never overwrites an edited guide' => function () use ($boot, $root) {
        $a = $boot();
        $a->pdo->exec("UPDATE help_article_translations t JOIN help_articles h ON h.id = t.article_id SET t.title = 'Edited by Super Admin' WHERE h.slug = 'email-getting-started' AND t.locale = 'en'");
        $migration = require $root . '/database/migrations/0082_seed_marketing_help_articles.php';
        $seed = \Closure::bind(fn (\PDO $pdo, string $slug, int $order) => $this->seed($pdo, $slug, $order), $migration, get_class($migration));
        foreach (array_merge(MKT_HELP_ALL, MKT_HELP_FA_ONLY) as $slug) {
            $seed($a->pdo, $slug, 99);
        }
        LiteAssert::same(21, (int) $a->pdo->query('SELECT COUNT(*) FROM help_articles WHERE sort_order BETWEEN 40 AND 80')->fetchColumn());
        LiteAssert::same(0, (int) $a->pdo->query('SELECT COUNT(*) FROM help_articles WHERE sort_order = 99')->fetchColumn(), 'nothing re-inserted');
        LiteAssert::same('Edited by Super Admin', $a->pdo->query("SELECT t.title FROM help_article_translations t JOIN help_articles h ON h.id = t.article_id WHERE h.slug = 'email-getting-started' AND t.locale = 'en'")->fetchColumn());
    },

    'cleanup: roll back everything' => function () use ($h) {
        if ($h->app !== null) {
            $h->app->rollBack();
        }
        LiteAssert::true(true);
    },
];
