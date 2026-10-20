<?php

declare(strict_types=1);

/**
 * Help center: every signed-in user reads (/help, /help/{slug}, popup
 * fragment); only the Super Admin creates, edits and deletes
 * (/admin/help/*). HTML is sanitized. crm_local only, one transaction,
 * rolled back.
 */

require_once __DIR__ . '/Support/LocalApp.php';

use App\Infrastructure\Security\HtmlSanitizer;
use Tests\Lite\Support\LocalApp;

use function App\Infrastructure\Theme\help_link;

const HELP_SEED_SLUG = 'deal-contract-sales-project';

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
        $a->pdo->query('SELECT 1 FROM help_articles LIMIT 1');
    } catch (\Throwable $e) {
        $h->reason = 'local database unavailable or migration 0077 not run: ' . $e->getMessage();
        throw new LiteSkip($h->reason);
    }
    $h->app = $a;

    return $a;
};

$superAdmin = ['id' => 900888, 'organization_id' => null, 'role_id' => null, 'is_super_admin' => 1, 'team_id' => null,
    'department_id' => null, 'status' => 'active', 'first_name' => 'Super', 'last_name' => 'Admin', 'email' => 'super@example.test', 'language' => 'en'];

$article = fn (string $slug, array $tr, string $published = '1') => [
    'slug' => $slug, 'sort_order' => '5', 'is_published' => $published, 'tr' => $tr,
];

return [
    'H1. migration seeded the first guide in all five languages' => function () use ($boot) {
        $a = $boot();
        $locales = $a->pdo->query(
            "SELECT GROUP_CONCAT(t.locale ORDER BY t.locale) FROM help_articles a JOIN help_article_translations t ON t.article_id = a.id WHERE a.slug = '" . HELP_SEED_SLUG . "'"
        )->fetchColumn();
        LiteAssert::same('ar,en,fa,fr,tr', $locales);
    },

    'H2. org users read the list and the guide in every language (right direction, no raw keys)' => function () use ($boot) {
        $a = $boot();
        foreach (['en' => 'ltr', 'fa' => 'rtl', 'ar' => 'rtl', 'tr' => 'ltr', 'fr' => 'ltr'] as $locale => $dir) {
            $a->locale($locale);
            foreach ([$a->admin, $a->noRole] as $user) {
                $a->as($user);
                $list = $a->request('GET', '/help', ['per_page' => '100'])['body'];
                LiteAssert::contains('href="/help/' . HELP_SEED_SLUG . '"', $list, "list link ({$locale})");
                LiteAssert::contains('dir="' . $dir . '"', $list);
                LiteAssert::notContains('help.', strip_tags($list), "raw key on list ({$locale})");
                LiteAssert::notContains('/admin/help/create', $list, 'no manage button for org users');

                $show = $a->request('GET', '/help/' . HELP_SEED_SLUG)['body'];
                LiteAssert::contains('class="help-content"', $show);
                LiteAssert::contains('CTR-0001', $show);
                LiteAssert::notContains('/admin/help/', $show, 'no edit/delete for org users');
            }
        }
        $a->locale('en');
        $a->as($a->admin);
    },

    'H3. the sidebar shows the guide link to org users' => function () use ($boot) {
        $a = $boot();
        $a->as($a->noRole);
        LiteAssert::contains('href="/help" class="nav-item"', $a->request('GET', '/help', ['per_page' => '100'])['body']);
        $a->as($a->admin);
    },

    'H4. popup fragment has no layout and links to the full guide' => function () use ($boot) {
        $a = $boot();
        $body = $a->request('GET', '/help/' . HELP_SEED_SLUG, ['fragment' => '1'])['body'];
        LiteAssert::notContains('<html', $body);
        LiteAssert::contains('class="help-content"', $body);
        LiteAssert::contains('href="/help/' . HELP_SEED_SLUG . '"', $body);
    },

    'H5. org users (even org_admin) cannot manage guides' => function () use ($boot) {
        $a = $boot();
        $id = (int) $a->pdo->query("SELECT id FROM help_articles WHERE slug = '" . HELP_SEED_SLUG . "'")->fetchColumn();
        foreach ([$a->admin, $a->noRole] as $user) {
            $a->as($user);
            LiteAssert::same('forbidden', $a->outcome('GET', '/admin/help/create'));
            LiteAssert::same('forbidden', $a->outcome('GET', "/admin/help/{$id}/edit"));
            LiteAssert::same('forbidden', $a->outcome('POST', '/admin/help', [], ['slug' => 'x', 'tr' => ['en' => ['title' => 'x', 'body' => 'x']]]));
            LiteAssert::same('forbidden', $a->outcome('POST', "/admin/help/{$id}", [], ['slug' => HELP_SEED_SLUG]));
            LiteAssert::same('forbidden', $a->outcome('POST', "/admin/help/{$id}/delete"));
        }
        LiteAssert::same(1, (int) $a->pdo->query("SELECT COUNT(*) FROM help_articles WHERE slug = '" . HELP_SEED_SLUG . "'")->fetchColumn());
        $a->as($a->admin);
    },

    'H6. CSRF is required on every management POST' => function () use ($boot, $superAdmin) {
        $a = $boot();
        $a->as($superAdmin);
        LiteAssert::same('csrf', $a->outcome('POST', '/admin/help', [], ['_csrf' => 'bad']));
        LiteAssert::same('csrf', $a->outcome('POST', '/admin/help/1/delete', [], ['_csrf' => 'bad']));
        $a->as($a->admin);
    },

    'H7. Super Admin creates a guide; HTML is sanitized; empty languages fall back to English' => function () use ($boot, $superAdmin, $article) {
        $a = $boot();
        $a->as($superAdmin);
        LiteAssert::same('ok', $a->outcome('GET', '/admin/help/create'));
        $res = $a->request('POST', '/admin/help', [], $article('lite-help-guide', [
            'en' => ['title' => 'Lite guide', 'summary' => 'Sum', 'body' => '<h2 onclick="x()">Hi</h2><script>alert(1)</script><a href="javascript:alert(1)">bad</a><a href="/contracts" target="_blank">ok</a><p style="color:red">text</p>'],
            'fa' => ['title' => '', 'summary' => '', 'body' => ''],
        ]));
        LiteAssert::same('/help/lite-help-guide', $res['location']);
        $body = $a->pdo->query("SELECT t.body FROM help_articles a JOIN help_article_translations t ON t.article_id = a.id WHERE a.slug = 'lite-help-guide' AND t.locale = 'en'")->fetchColumn();
        LiteAssert::notContains('<script', $body);
        LiteAssert::notContains('onclick', $body);
        LiteAssert::notContains('javascript:', $body);
        LiteAssert::notContains('style=', $body);
        LiteAssert::contains('<h2>Hi</h2>', $body);
        LiteAssert::contains('rel="noopener noreferrer"', $body);
        LiteAssert::same(1, (int) $a->pdo->query("SELECT COUNT(*) FROM help_article_translations t JOIN help_articles a ON a.id = t.article_id WHERE a.slug = 'lite-help-guide'")->fetchColumn(), 'empty fa not stored');

        $a->as($a->admin);
        $a->locale('fa');
        LiteAssert::contains('Lite guide', $a->request('GET', '/help/lite-help-guide')['body'], 'fa falls back to en');
        $a->locale('en');
    },

    'H8. validation: bad slug, taken slug, missing English, half-filled language' => function () use ($boot, $superAdmin, $article) {
        $a = $boot();
        $a->as($superAdmin);
        $before = (int) $a->pdo->query('SELECT COUNT(*) FROM help_articles')->fetchColumn();
        $en = ['title' => 'T', 'summary' => '', 'body' => '<p>b</p>'];
        foreach ([
            $article('Bad Slug!', ['en' => $en]),
            $article(HELP_SEED_SLUG, ['en' => $en]),
            $article('lite-no-en', ['fa' => ['title' => 'ع', 'summary' => '', 'body' => '<p>م</p>']]),
            $article('lite-half', ['en' => $en, 'tr' => ['title' => 'Başlık', 'summary' => '', 'body' => '']]),
        ] as $payload) {
            LiteAssert::same(422, $a->request('POST', '/admin/help', [], $payload)['status'], 'rejected: ' . $payload['slug']);
        }
        LiteAssert::same($before, (int) $a->pdo->query('SELECT COUNT(*) FROM help_articles')->fetchColumn());
        $a->as($a->admin);
    },

    'H9. unpublished guides are visible only to the Super Admin' => function () use ($boot, $superAdmin, $article) {
        $a = $boot();
        $a->as($superAdmin);
        $a->request('POST', '/admin/help', [], $article('lite-hidden', ['en' => ['title' => 'Hidden', 'summary' => '', 'body' => '<p>x</p>']], '0'));
        LiteAssert::contains('href="/help/lite-hidden"', $a->request('GET', '/help', ['per_page' => '100'])['body']);
        LiteAssert::same('ok', $a->outcome('GET', '/help/lite-hidden'));

        $a->as($a->noRole);
        LiteAssert::notContains('lite-hidden', $a->request('GET', '/help', ['per_page' => '100'])['body']);
        LiteAssert::same('notfound', $a->outcome('GET', '/help/lite-hidden'));
        $a->as($a->admin);
    },

    'H10. Super Admin edits (translations replaced) and deletes' => function () use ($boot, $superAdmin, $article) {
        $a = $boot();
        $a->as($superAdmin);
        $id = (int) $a->pdo->query("SELECT id FROM help_articles WHERE slug = 'lite-help-guide'")->fetchColumn();
        LiteAssert::contains('name="tr[en][title]"', $a->request('GET', "/admin/help/{$id}/edit")['body']);
        $res = $a->request('POST', "/admin/help/{$id}", [], $article('lite-help-renamed', [
            'en' => ['title' => 'Renamed', 'summary' => '', 'body' => '<p>new</p>'],
            'fr' => ['title' => 'Renommé', 'summary' => '', 'body' => '<p>nouveau</p>'],
        ]));
        LiteAssert::same('/help/lite-help-renamed', $res['location']);
        LiteAssert::same('en,fr', $a->pdo->query("SELECT GROUP_CONCAT(locale ORDER BY locale) FROM help_article_translations WHERE article_id = {$id}")->fetchColumn());

        $a->request('POST', "/admin/help/{$id}/delete");
        LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM help_articles WHERE id = {$id}")->fetchColumn());
        LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM help_article_translations WHERE article_id = {$id}")->fetchColumn());
        LiteAssert::same('notfound', $a->outcome('GET', '/help/lite-help-renamed'));
        $a->as($a->admin);
    },

    'H11. sanitizer keeps structure, drops dangerous markup' => function () {
        $out = HtmlSanitizer::clean('<div class="help-callout"><p>a<img src="x.png" onerror="y()"></p></div><iframe src="https://e.x"></iframe><a href="//evil.example">p</a><a href="mailto:a@b.c">m</a><custom>kept text</custom><!-- c -->');
        LiteAssert::contains('<div class="help-callout"><p>a<img src="x.png"></p></div>', $out);
        LiteAssert::notContains('iframe', $out);
        LiteAssert::notContains('evil.example', $out);
        LiteAssert::contains('href="mailto:a@b.c"', $out);
        LiteAssert::contains('kept text', $out);
        LiteAssert::notContains('<custom', $out);
        LiteAssert::notContains('<!--', $out);
        LiteAssert::same('<p>سلام</p>', HtmlSanitizer::clean('<p>سلام</p>'), 'UTF-8 preserved');
    },

    'H12. help_link() renders a labelled link that opens the popup' => function () use ($boot) {
        $boot();
        $html = help_link(HELP_SEED_SLUG);
        LiteAssert::contains('href="/help/' . HELP_SEED_SLUG . '"', $html);
        LiteAssert::contains('data-related-url="/help/' . HELP_SEED_SLUG . '?fragment=1"', $html);
        LiteAssert::contains('data-related-dialog="help-dialog"', $html);
        LiteAssert::contains('aria-label="', $html);
        LiteAssert::contains('#i-help-circle', $html);
    },

    'H13. rollback: test guides are gone, the seeded guide is intact' => function () use ($boot) {
        $a = $boot();
        $a->rollBack();
        LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM help_articles WHERE slug LIKE 'lite-%'")->fetchColumn());
        LiteAssert::same(5, (int) $a->pdo->query("SELECT COUNT(*) FROM help_article_translations t JOIN help_articles a ON a.id = t.article_id WHERE a.slug = '" . HELP_SEED_SLUG . "'")->fetchColumn());
    },
];
