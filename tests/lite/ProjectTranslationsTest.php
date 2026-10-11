<?php

declare(strict_types=1);

/**
 * Phase 5 Step 3 — Translations: every Projects key exists in all five
 * language files with matching placeholders, and every Projects page and
 * panel renders in fa, ar, tr, en and fr with the right text direction
 * and no raw translation key in the visible text.
 * crm_local only, one transaction, rolled back.
 */

require_once __DIR__ . '/Support/LocalApp.php';
require_once __DIR__ . '/Support/ProjectsLite.php';

use Modules\Contracts\Services\ContractService;
use Modules\Projects\Services\ProjectService;
use Tests\Lite\Support\ProjectsLite;

$root = dirname(__DIR__, 2);
$langs = ['en', 'fa', 'ar', 'tr', 'fr'];

/** Every key the module can ask for: literal t('…') calls + the dynamic families. */
$usedKeys = function () use ($root): array {
    $keys = [];
    $files = array_merge(
        glob($root . '/themes/default/templates/projects/*.php') ?: [],
        iterator_to_array(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/modules/Projects', \FilesystemIterator::SKIP_DOTS)))
    );
    foreach ($files as $file) {
        $code = (string) file_get_contents((string) $file);
        preg_match_all("/t\\('((?:projects|nav)\\.[a-z0-9_.]+)'/", $code, $m);
        preg_match_all("/'(projects\\.(?:error|flash)\\.[a-z0-9_]+)'/", $code, $m2);
        $keys = array_merge($keys, $m[1], $m2[1]);
    }
    foreach (ProjectService::STATUSES as $s) {
        $keys[] = "projects.status.{$s}";
    }
    foreach (array_keys(ProjectService::TRANSITIONS) as $act) {
        $keys[] = "projects.action.{$act}";
    }
    foreach (ProjectService::LINKABLE as $type) {
        $keys[] = "projects.created_from_{$type}";
        $keys[] = "projects.panel_empty_{$type}";
    }
    $keys[] = 'projects.record';
    $keys[] = 'projects.panel_empty';
    $keys[] = 'nav.group_projects';

    // t('projects.status.' . $s) is captured as the prefix "projects.status." — listed explicitly above.
    return array_values(array_unique(array_filter($keys, fn (string $k) => !str_ends_with($k, '.') && !str_ends_with($k, '_'))));
};

$h = new \stdClass();
$h->pages = null;
$booter = ProjectsLite::booter(true);
$boot = function () use ($booter, $h) {
    $a = $booter();
    if ($h->pages !== null) {
        return $a;
    }
    $a->as($a->admin);
    $svc = ProjectsLite::service($a);
    $contact = ProjectsLite::contactId($a);
    $company = ProjectsLite::companyId($a);
    $active = $svc->create($a->admin, ['name' => 'LiteTest i18n project', 'contact_id' => (string) $contact, 'company_id' => (string) $company, 'start_date' => '2026-01-01', 'expected_end_date' => '2026-06-30', 'description' => 'd']);
    $svc->changeStatus($a->admin, (int) $active['id'], 'start');
    $svc->addMember($a->admin, (int) $active['id'], (int) $a->member['id']);
    $completed = $svc->create($a->admin, ['name' => 'LiteTest i18n done']);
    $svc->changeStatus($a->admin, (int) $completed['id'], 'start');
    $svc->changeStatus($a->admin, (int) $completed['id'], 'complete');
    $onHold = $svc->create($a->admin, ['name' => 'LiteTest i18n hold']);
    $svc->changeStatus($a->admin, (int) $onHold['id'], 'plan');
    $svc->changeStatus($a->admin, (int) $onHold['id'], 'hold');
    $contract = $a->container()->make(ContractService::class)->create($a->admin, ['title' => 'LiteTest i18n contract']);
    $svc->link($a->admin, (int) $active['id'], 'contract', (int) $contract['id']);
    $h->pages = [
        '/projects' => [],
        '/projects/create' => [],
        '/projects/' . $active['id'] => [],
        '/projects/' . $completed['id'] => [],
        '/projects/' . $onHold['id'] => [],
        '/projects/' . $active['id'] . '/edit' => [],
        '/projects/settings' => [],
        '/contacts/' . $contact => [],
        '/companies/' . $company => [],
        '/contracts/' . $contract['id'] => [],
        '/projects/create?from_contract' => ['from_contract' => (string) $contract['id']],
    ];
    $deal = ProjectsLite::dealId($a);
    if ($deal !== null) {
        $svc->link($a->admin, (int) $active['id'], 'deal', $deal);
        $h->pages['/deals/' . $deal] = [];
        $h->pages['/projects/create?from_deal'] = ['from_deal' => (string) $deal];
    }

    return $a;
};

/** Visible text only (no tags/attributes), so data-related-panel="projects.contact" etc. don't count. */
$rawKeys = function (string $html): array {
    $text = preg_replace('#<(script|style)\b.*?</\1>#s', '', $html);
    $text = html_entity_decode(strip_tags((string) $text));
    preg_match_all('/\b(?:projects|nav)\.[a-z_]+(?:\.[a-z_]+)*\b/', $text, $m);

    return array_values(array_unique($m[0]));
};

$tests = [
    'every Projects key used in code exists in all five language files' => function () use ($usedKeys, $langs, $root) {
        $keys = $usedKeys();
        LiteAssert::true(count($keys) > 60, 'key extraction found only ' . count($keys) . ' keys');
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

    'placeholders match across languages (:count etc.)' => function () use ($usedKeys, $langs, $root) {
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

    'all five language files define the same projects.* keys' => function () use ($langs, $root) {
        $sets = [];
        foreach ($langs as $lang) {
            $sets[$lang] = array_values(array_filter(array_keys(require $root . "/lang/{$lang}.php"), fn ($k) => str_starts_with($k, 'projects.')));
            sort($sets[$lang]);
        }
        foreach ($langs as $lang) {
            LiteAssert::same($sets['en'], $sets[$lang], "{$lang} key set");
        }
    },
];

foreach ($langs as $lang) {
    $tests["all Projects pages and panels render in {$lang} with no raw keys"] = function () use ($boot, $h, $lang, $rawKeys) {
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
        $r = $a->request('POST', '/projects', [], ['name' => '', 'start_date' => '2026-02-30', 'owner_id' => '999999999']);
        foreach ($rawKeys($r['body']) as $key) {
            $problems[] = "422 form: raw key {$key}";
        }
        $a->locale('en');
        LiteAssert::same([], $problems, implode(' | ', array_slice($problems, 0, 15)));
    };
}

$tests['rollback leaves no test data behind'] = function () use ($boot) {
    $a = $boot();
    $a->locale('en');
    $a->rollBack();
    LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM projects WHERE name LIKE 'LiteTest%'")->fetchColumn());
};

return $tests;
