<?php

declare(strict_types=1);

/**
 * Lightweight plain-PHP test runner (PHPUnit is not installed on this
 * project). Each tests/lite/*Test.php file returns an array of
 * "test name" => callable. A test passes when it returns without
 * throwing; LiteAssert failures and any other Throwable fail it; a
 * LiteSkip marks it SKIPPED (counted separately, never as passed).
 *
 * Usage (project root):
 *   php tests/lite/run.php            all tests
 *   php tests/lite/run.php Record     only files whose name contains "Record"
 *
 * Exit code 0 only when nothing failed.
 */

require dirname(__DIR__, 2) . '/app/autoload.php';
require __DIR__ . '/Assert.php';

$filter = $argv[1] ?? '';
$files = glob(__DIR__ . '/*Test.php') ?: [];
sort($files);

$passed = $failed = $skipped = 0;
$failures = [];

foreach ($files as $file) {
    $suite = basename($file, '.php');
    if ($filter !== '' && !str_contains($suite, $filter)) {
        continue;
    }

    $tests = require $file;
    echo "\n{$suite}\n";

    foreach ($tests as $name => $test) {
        try {
            $test();
            $passed++;
            echo "  PASS  {$name}\n";
        } catch (LiteSkip $e) {
            $skipped++;
            echo "  SKIP  {$name} — {$e->getMessage()}\n";
        } catch (\Throwable $e) {
            $failed++;
            $where = basename($e->getFile()) . ':' . $e->getLine();
            $failures[] = "{$suite} › {$name}: " . get_class($e) . ': ' . $e->getMessage() . " ({$where})";
            echo "  FAIL  {$name}\n        " . get_class($e) . ': ' . $e->getMessage() . " ({$where})\n";
        }
    }
}

$total = $passed + $failed + $skipped;
echo "\n" . str_repeat('-', 60) . "\n";
echo 'Result: ' . ($failed === 0 ? 'PASS' : 'FAIL') . "\n";
echo "Tests: {$total}  Passed: {$passed}  Failed: {$failed}  Skipped: {$skipped}\n";
foreach ($failures as $line) {
    echo "  - {$line}\n";
}

exit($failed === 0 ? 0 : 1);
