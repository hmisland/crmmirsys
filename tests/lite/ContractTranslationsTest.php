<?php

declare(strict_types=1);

/**
 * Step 2.10 — F. Translations: every Contracts key exists in all five
 * language files, and every Contracts page/panel renders in fa, ar, tr,
 * en and fr with no raw translation key left in the visible text.
 * crm_local only, one transaction, rolled back.
 */

require_once __DIR__ . '/Support/LocalApp.php';

use Modules\Contracts\Services\ContractService;
use Tests\Lite\Support\LocalApp;

$root = dirname(__DIR__, 2);
$langs = ['en', 'fa', 'ar', 'tr', 'fr'];

/** Every key the module can ask for: literal t('…') calls + the dynamic families. */
$usedKeys = function () use ($root): array {
    $keys = [];
    $files = array_merge(
        glob($root . '/themes/default/templates/contracts/*.php') ?: [],
        iterator_to_array(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/modules/Contracts', \FilesystemIterator::SKIP_DOTS)))
    );
    foreach ($files as $file) {
        $code = (string) file_get_contents((string) $file);
        preg_match_all("/t\\('((?:contracts|nav)\\.[a-z0-9_.]+)'/", $code, $m);
        preg_match_all("/'(contracts\\.(?:error|flash)\\.[a-z0-9_]+)'/", $code, $m2);
        $keys = array_merge($keys, $m[1], $m2[1]);
    }
    foreach (['draft', 'pending_approval', 'active', 'expired', 'terminated', 'cancelled'] as $s) {
        $keys[] = "contracts.status.{$s}";
    }
    foreach (['original', 'renewal', 'amendment'] as $r) {
        $keys[] = "contracts.relation.{$r}";
    }
    foreach (['submit', 'return_to_draft', 'approve', 'activate', 'cancel', 'terminate', 'renew'] as $act) {
        $keys[] = "contracts.action.{$act}";
    }
    $keys[] = 'contracts.record';

    // t('contracts.status.' . $s) is captured as the prefix "contracts.status." —
    // those dynamic families are listed explicitly above.
    return array_values(array_unique(array_filter($keys, fn (string $k) => !str_ends_with($k, '.'))));
};

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
    } catch (\Throwable $e) {
        $h->reason = 'local database unavailable: ' . $e->getMessage();
        throw new LiteSkip($h->reason);
    }
    $a->activateContracts();
    $svc = $a->container()->make(ContractService::class);
    $contact = (string) $a->pdo->query("SELECT id FROM contacts WHERE organization_id = {$a->orgId} ORDER BY id LIMIT 1")->fetchColumn();
    $deal = $a->pdo->query("SELECT id FROM deals WHERE organization_id = {$a->orgId} ORDER BY id LIMIT 1")->fetchColumn();
    $currency = (string) $a->pdo->query('SELECT id FROM currencies WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
    $svc->createCategory($a->admin, 'LiteTest i18n category');
    $active = $svc->create($a->admin, ['title' => 'LiteTest i18n', 'contact_id' => $contact, 'start_date' => '2025-01-01', 'end_date' => '2025-12-31', 'value' => '1500.25', 'currency_id' => $currency, 'renewal_notice_days' => '30', 'auto_renew' => '1', 'description' => 'd', 'terms' => 't']);
    $svc->activate($a->admin, (int) $active['id']);
    if ($deal !== false) {
        $svc->link($a->admin, (int) $active['id'], 'deal', (int) $deal);
    }
    $renewal = $svc->renew($a->admin, (int) $active['id']); // expired original + renewal history
    $draft = $svc->create($a->admin, ['title' => 'LiteTest i18n draft']);
    $h->pages = [
        '/contracts' => [],
        '/contracts/create' => [],
        '/contracts/' . $active['id'] => [],
        '/contracts/' . $renewal['id'] => [],
        '/contracts/' . $draft['id'] => [],
        '/contracts/' . $draft['id'] . '/edit' => [],
        '/contracts/categories' => [],
        '/contracts/settings' => [],
        '/contacts/' . $contact => [],
    ];
    if ($deal !== false) {
        $h->pages['/deals/' . $deal] = [];
        $h->pages['/contracts/create?from_deal'] = ['from_deal' => (string) $deal];
    }
    $h->app = $a;

    return $a;
};

/** Visible text only (no tags/attributes), so data-related-panel="contracts.contact" etc. don't count. */
$rawKeys = function (string $html): array {
    $text = preg_replace('#<(script|style)\b.*?</\1>#s', '', $html);
    $text = html_entity_decode(strip_tags((string) $text));
    preg_match_all('/\b(?:contracts|nav)\.[a-z_]+(?:\.[a-z_]+)*\b/', $text, $m);

    return array_values(array_unique($m[0]));
};

$tests = [
    'F1. every Contracts key used in code exists in all five language files' => function () use ($usedKeys, $langs, $root) {
        $keys = $usedKeys();
        LiteAssert::true(count($keys) > 80, 'key extraction found only ' . count($keys) . ' keys');
        $missing = [];
        foreach ($langs as $lang) {
            $strings = require $root . "/lang/{$lang}.php";
            foreach ($keys as $key) {
                if (!isset($strings[$key]) || trim((string) $strings[$key]) === '') {
                    $missing[] = "{$lang}:{$key}";
                }
            }
        }
        LiteAssert::same([], $missing, implode(', ', array_slice($missing, 0, 20)));
    },

    'F2. placeholders match across languages (:count etc.)' => function () use ($usedKeys, $langs, $root) {
        $en = require $root . '/lang/en.php';
        $bad = [];
        foreach ($langs as $lang) {
            $strings = require $root . "/lang/{$lang}.php";
            foreach ($usedKeys() as $key) {
                preg_match_all('/:[a-z_]+/', (string) ($en[$key] ?? ''), $want);
                preg_match_all('/:[a-z_]+/', (string) ($strings[$key] ?? ''), $have);
                sort($want[0]);
                sort($have[0]);
                if ($want[0] !== $have[0]) {
                    $bad[] = "{$lang}:{$key}";
                }
            }
        }
        LiteAssert::same([], $bad, implode(', ', $bad));
    },
];

foreach ($langs as $lang) {
    $tests["F3. all Contracts pages render in {$lang} with no raw keys"] = function () use ($boot, $h, $lang, $rawKeys) {
        $a = $boot();
        $a->as($a->admin);
        $a->locale($lang);
        $problems = [];
        foreach ($h->pages as $path => $query) {
            $realPath = explode('?', $path)[0];
            try {
                $r = $a->request('GET', $realPath, $query);
            } catch (\Throwable $e) {
                $problems[] = "{$path}: " . get_class($e) . ' ' . $e->getMessage();
                continue;
            }
            if ($r['status'] !== 200) {
                $problems[] = "{$path}: status {$r['status']}";
            }
            if (!str_contains($r['body'], 'dir="' . (in_array($lang, ['fa', 'ar'], true) ? 'rtl' : 'ltr') . '"')) {
                $problems[] = "{$path}: wrong text direction";
            }
            foreach ($rawKeys($r['body']) as $key) {
                $problems[] = "{$path}: raw key {$key}";
            }
        }
        // A validation error page too (422), so error messages are covered.
        $r = $a->request('POST', '/contracts', [], ['title' => '', 'start_date' => '2026-02-30', 'value' => 'x']);
        foreach ($rawKeys($r['body']) as $key) {
            $problems[] = "422 form: raw key {$key}";
        }
        LiteAssert::same([], $problems, implode(' | ', array_slice($problems, 0, 15)));
    };
}

$tests['F4. rollback leaves no test data behind'] = function () use ($boot) {
    $a = $boot();
    $a->locale('en');
    $a->rollBack();
    LiteAssert::same(0, (int) $a->pdo->query('SELECT COUNT(*) FROM contracts')->fetchColumn());
};

return $tests;
