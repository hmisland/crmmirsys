<?php

declare(strict_types=1);

/**
 * Offline / office install helper (Windows LAN edition) — CLI only.
 *
 *   php windows-offline\setup.php migrate              core + module migrations, then module list sync
 *   php windows-offline\setup.php superadmin <email>   make that user Super Admin and enable every
 *                                                      active module for the user's organization
 *
 * Uses the application's own bootstrap (like app/bin/scheduler.php) and its
 * Migrator / ModuleService — nothing here changes application code.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require $root . '/app/autoload.php';

$app = new \App\Core\Application($root);
$app->loadRoutes();
$pdo = \App\Infrastructure\Database\Connection::pdo();

$command = $argv[1] ?? '';

if ($command === 'migrate') {
    $core = new \App\Infrastructure\Database\Migrator($pdo, $root . '/database/migrations', 'migrations');
    $applied = $core->run();
    echo 'core: ' . count($applied) . " migration(s)\n";
    foreach (\App\Infrastructure\Modules\ModuleRegistry::instance()->all() as $manifest) {
        if (!is_dir($manifest->path . '/Migrations')) {
            continue;
        }
        $m = new \App\Infrastructure\Database\Migrator($pdo, $manifest->path . '/Migrations', 'module_migrations', $manifest->key);
        $done = $m->run();
        if ($done !== []) {
            echo $manifest->key . ': ' . count($done) . " migration(s)\n";
        }
    }
    $app->container->make(\App\Application\Services\ModuleService::class)->syncFromRegistry();
    echo "modules synced\n";
    exit(0);
}

if ($command === 'license-ok') {
    require __DIR__ . '/license/OfflineLicense.php';
    $status = (new MirsysOfflineLicense($root))->status();
    echo ($status['ok'] ? 'valid' : 'invalid: ' . $status['reason']) . "\n";
    exit($status['ok'] ? 0 : 1);
}

if ($command === 'superadmin') {
    $email = strtolower(trim((string) ($argv[2] ?? '')));
    $stmt = $pdo->prepare('SELECT id, organization_id FROM users WHERE LOWER(email) = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch(\PDO::FETCH_ASSOC);
    if ($user === false) {
        fwrite(STDERR, "No user with email {$email}. Register in the browser first.\n");
        exit(1);
    }
    $pdo->prepare('UPDATE users SET is_super_admin = 1, status = \'active\', email_verified_at = COALESCE(email_verified_at, UTC_TIMESTAMP()) WHERE id = ?')
        ->execute([(int) $user['id']]);
    echo "Super Admin: {$email}\n";
    if (!empty($user['organization_id'])) {
        $app->container->make(\App\Application\Services\ModuleService::class)->syncFromRegistry();
        $pdo->prepare(
            "INSERT INTO organization_modules (organization_id, module_id, enabled)
             SELECT ?, id, 1 FROM modules WHERE status = 'active'
             ON DUPLICATE KEY UPDATE enabled = 1"
        )->execute([(int) $user['organization_id']]);
        echo "All active modules enabled for organization #{$user['organization_id']}\n";
    }
    exit(0);
}

fwrite(STDERR, "Usage: php windows-offline\\setup.php migrate | superadmin <email>\n");
exit(2);
