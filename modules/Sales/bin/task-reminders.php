<?php

declare(strict_types=1);

/**
 * Task Manager processor — run by cron, every minute (cPanel → Cron Jobs):
 *
 *   * * * * * /usr/local/bin/php /home/ACCOUNT/public_html/modules/Sales/bin/task-reminders.php >/dev/null 2>&1
 *
 * In this order, for every organization by its own local time:
 *  1. recurring tasks (T7): creates each series' next occurrence when due
 *     (TaskRecurrenceService);
 *  2. reminders (T6): turns due reminders into in-app notifications for the
 *     tasks' assignees (TaskReminderService);
 *  3. reminder emails (T7): sends the emails of reminders that asked for one,
 *     through the CRM's mailer (MAIL_DRIVER), retrying failures.
 * Safe to overlap and to re-run: every occurrence, notification and email is
 * claimed atomically and handled once, and a lock file keeps a second copy
 * from starting. CLI only — the web server never runs this file (and
 * /modules is blocked by the root .htaccess).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__, 3);
require $root . '/app/autoload.php';

$lockFile = $root . '/storage/cache/task-reminders.lock';
$lock = @fopen($lockFile, 'c');
if ($lock === false) {
    fwrite(STDERR, "Cannot create {$lockFile} — check that storage/cache exists and is writable.\n");
    exit(1);
}
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0); // another run is still going
}

$app = new \App\Core\Application($root);
$app->loadRoutes();

$container = $app->container;
if (!$container->has(\Modules\Sales\Services\TaskReminderService::class) || !$container->has(\Modules\Sales\Services\TaskRecurrenceService::class)) {
    fwrite(STDERR, "Sales module is not installed.\n");
    exit(1);
}
$service = $container->make(\Modules\Sales\Services\TaskReminderService::class);

$recurring = $container->make(\Modules\Sales\Services\TaskRecurrenceService::class)->runAll();
$stats = $service->runAll();

echo sprintf(
    "recurring: organizations=%d created=%d ended=%d | reminders: organizations=%d reminders=%d notifications=%d emails_sent=%d emails_failed=%d | errors=%d\n",
    $recurring['organizations'], $recurring['created'], $recurring['ended'],
    $stats['organizations'], $stats['reminders'], $stats['notifications'], $stats['emails_sent'], $stats['emails_failed'],
    count($recurring['errors']) + count($stats['errors'])
);
foreach (array_merge($recurring['errors'], $stats['errors']) as $error) {
    fwrite(STDERR, $error . "\n");
}

flock($lock, LOCK_UN);
fclose($lock);
exit($recurring['errors'] === [] && $stats['errors'] === [] ? 0 : 1);
