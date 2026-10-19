<?php

declare(strict_types=1);

/**
 * Shared background scheduler — ONE cron entry, every minute
 * (cPanel → Cron Jobs):
 *
 *   * * * * * /usr/local/bin/php /home/ACCOUNT/public_html/app/bin/scheduler.php >/dev/null 2>&1
 *
 * Runs every task registered in App\Infrastructure\Scheduling\ScheduledTasks
 * within ~50 seconds: "integrations" (queued webhooks, incremental syncs,
 * retries) and module tasks such as "cx.invitations". A lock file keeps
 * a second copy from starting while one is still running. CLI only — the
 * web server never runs this file (/app is blocked by the root .htaccess).
 *
 * Optional argument: time budget in seconds (5–300, default 50).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__, 2);
require $root . '/app/autoload.php';

$lockFile = $root . '/storage/cache/scheduler.lock';
$lock = @fopen($lockFile, 'c');
if ($lock === false) {
    fwrite(STDERR, "Cannot create {$lockFile} — check that storage/cache exists and is writable.\n");
    exit(1);
}
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0); // another run is still working
}

$app = new \App\Core\Application($root);
$app->loadRoutes();

$budget = isset($argv[1]) && ctype_digit($argv[1]) ? max(5, min(300, (int) $argv[1])) : 50;
$summaries = $app->container->make(\App\Infrastructure\Scheduling\ScheduledTasks::class)->runAll($budget);
foreach ($summaries as $name => $summary) {
    echo $name . ': ' . $summary . "\n";
}

flock($lock, LOCK_UN);
fclose($lock);
