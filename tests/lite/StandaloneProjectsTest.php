<?php

declare(strict_types=1);

/**
 * Phase 5 Step 3 — Projects with Core ONLY, for real: copies the app to a
 * temporary folder whose modules/ holds nothing but Projects (Sales, Contracts,
 * Finance, Followups... are physically absent), then runs
 * standalone/projects-child.php there in a separate PHP process. The copy
 * (including the local .env it needs to reach crm_local) is deleted
 * afterwards. Each child check becomes one test here.
 */

$results = null;

$run = function () use (&$results): array {
    if ($results !== null) {
        return $results;
    }

    $source = dirname(__DIR__, 2);
    $target = sys_get_temp_dir() . '/crm-standalone-projects-' . bin2hex(random_bytes(4));

    $copy = function (string $from, string $to) use (&$copy): void {
        if (is_dir($from)) {
            @mkdir($to, 0777, true);
            foreach (scandir($from) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $copy($from . '/' . $entry, $to . '/' . $entry);
                }
            }
        } else {
            copy($from, $to);
        }
    };
    $remove = function (string $path) use (&$remove): void {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $remove($path . '/' . $entry);
                }
            }
            @rmdir($path);
        } elseif (file_exists($path)) {
            @unlink($path);
        }
    };

    try {
        foreach (['app', 'config', 'database', 'lang', 'routes', 'themes'] as $dir) {
            $copy("{$source}/{$dir}", "{$target}/{$dir}");
        }
        $copy("{$source}/modules/Projects", "{$target}/modules/Projects");
        $copy("{$source}/.env", "{$target}/.env");
        foreach (['logs', 'cache', 'uploads', 'mail', 'file-library'] as $dir) {
            @mkdir("{$target}/storage/{$dir}", 0777, true);
        }

        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/standalone/projects-child.php') . ' ' . escapeshellarg($target) . ' 2>&1';
        $output = (string) shell_exec($cmd);
        $json = json_decode(substr($output, (int) strpos($output, '{"checks"')), true);
        $results = is_array($json['checks'] ?? null) ? $json['checks'] : [['child process output', false, mb_substr($output, 0, 600)]];
    } finally {
        $remove($target);
    }

    if (is_dir($target)) {
        $results[] = ['temporary copy removed', false, $target];
    }

    return $results;
};

$tests = [];
$names = [
    'only Projects is installed on disk',
    'Sales and Contracts classes are really absent',
    'record types: project registered, deal and contract not',
    'project list renders (200), sidebar has Projects, no Deals/Contracts link',
    'create through the real route',
    'full lifecycle: plan → start → hold → resume → complete → reopen',
    'members: add and list',
    'notes on a project (Core /records route)',
    'project page renders with no deal or contract UI',
    'contact page shows the Projects panel',
    'deal/contract features degrade: ?from_deal= and ?from_contract= are 404, their routes do not exist',
    'service: linking a deal or a contract is refused',
    'links created while Sales/Contracts existed show as plain text',
    'settings page works',
    'Core pages still work without any other optional module (contacts list, companies, leads)',
];
foreach ($names as $name) {
    $tests["standalone: {$name}"] = function () use ($run, $name) {
        foreach ($run() as [$checkName, $ok, $detail]) {
            if ($checkName === $name) {
                LiteAssert::true($ok, $detail !== '' ? $detail : 'check failed');

                return;
            }
        }
        throw new LiteAssertionFailed('check did not run: ' . json_encode(array_column($run(), 0)));
    };
}
$tests['standalone: no unexpected checks and the temporary copy is gone'] = function () use ($run, $names) {
    LiteAssert::same($names, array_column($run(), 0));
};

return $tests;
