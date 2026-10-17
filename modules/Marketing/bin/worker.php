<?php

declare(strict_types=1);

/**
 * Marketing send-queue worker — run by cron, every minute (cPanel →
 * Cron Jobs):
 *
 *   * * * * * /usr/local/bin/php /home/ACCOUNT/public_html/modules/Marketing/bin/worker.php >/dev/null 2>&1
 *
 * Sends queued campaign messages of every organization within ~50
 * seconds, then fetches SMS delivery reports. Safe to overlap: rows are
 * claimed atomically, and a lock file keeps a second copy from starting.
 * CLI only — the web server never runs this file (and /modules is
 * blocked by the root .htaccess).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__, 3);
require $root . '/app/autoload.php';

$lockFile = $root . '/storage/cache/marketing-worker.lock';
$lock = @fopen($lockFile, 'c');
if ($lock === false) {
    fwrite(STDERR, "Cannot create {$lockFile} — check that storage/cache exists and is writable.\n");
    exit(1);
}
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0); // another worker is still running
}

$app = new \App\Core\Application($root);
$app->loadRoutes();

$worker = $app->container->has(\Modules\Marketing\Services\QueueWorker::class)
    ? $app->container->make(\Modules\Marketing\Services\QueueWorker::class)
    : null;
if ($worker === null) {
    fwrite(STDERR, "Marketing module is not installed.\n");
    exit(1);
}

$budget = isset($argv[1]) && ctype_digit($argv[1]) ? max(5, min(300, (int) $argv[1])) : 50;
$stats = $worker->runAll($budget);

echo sprintf("campaigns=%d processed=%d delivery_reports=%d\n", $stats['campaigns'], $stats['processed'], $stats['reports']);

flock($lock, LOCK_UN);
fclose($lock);
