<?php

declare(strict_types=1);

/**
 * Organization time zone + UTC storage (docs/organization-timezone-design.md).
 *
 * Resolver tests are pure (fixed clock, injected lookup). Integration tests
 * run in crm_local inside one rolled-back transaction. Migration 0083/0084
 * tests run on a throw-away database (crm_tz_test_*), created and dropped
 * here — never on crm_local or production.
 */

require_once __DIR__ . '/Support/LocalApp.php';

use App\Application\Services\OrganizationService;
use App\Infrastructure\Database\Connection;
use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Time\OrganizationTimezone;
use Tests\Lite\Support\LocalApp;

use function App\Infrastructure\Theme\local_time;

/** Resolver with fixed zones per organization id and a fixed UTC clock. */
$resolver = function (array $zones, string $utcNow = '2026-10-15 12:00:00'): OrganizationTimezone {
    return new OrganizationTimezone(
        fn (int $id) => $zones[$id] ?? null,
        'Asia/Tehran',
        fn () => new \DateTimeImmutable($utcNow, new \DateTimeZone('UTC')),
    );
};

$h = new \stdClass();
$h->app = null;
$boot = function () use ($h): LocalApp {
    if ($h->app === null) {
        $h->app = LocalApp::boot();
        $h->app->as($h->app->admin);
        $h->app->locale('en');
    }

    return $h->app;
};

return [
    // --- Resolver ---------------------------------------------------------------

    'TZ1 isolation: Tehran and Baku organizations never affect each other (incl. runAs workers)' => function () use ($resolver) {
        $tz = $resolver([1 => 'Asia/Tehran', 2 => 'Asia/Baku']);
        LiteAssert::same('2026-10-15 15:30:00', $tz->now(1)->format('Y-m-d H:i:s'));
        LiteAssert::same('2026-10-15 16:00:00', $tz->now(2)->format('Y-m-d H:i:s'));
        LiteAssert::same('2026-10-15 15:30:00', $tz->now(1)->format('Y-m-d H:i:s'), 'unchanged after reading B');

        $previous = OrganizationContext::has() ? OrganizationContext::id() : null;
        OrganizationContext::set(1);
        try {
            LiteAssert::same('Asia/Tehran', $tz->current()->getName());
            LiteAssert::same('Asia/Baku', OrganizationContext::runAs(2, fn () => $tz->current()->getName()));
            LiteAssert::same('Asia/Tehran', $tz->current()->getName(), 'restored after runAs');
        } finally {
            $previous !== null ? OrganizationContext::set($previous) : OrganizationContext::reset();
        }
        LiteAssert::same('UTC', date_default_timezone_get(), 'PHP global zone untouched');
    },

    'TZ2 storage ↔ local: one stored instant shows differently per organization and converts back exactly' => function () use ($resolver) {
        $tz = $resolver([1 => 'Asia/Tehran', 2 => 'Asia/Baku', 3 => 'Europe/Berlin']);
        LiteAssert::same('2026-10-15 03:30:00', $tz->toLocal('2026-10-15 00:00:00', 1)->format('Y-m-d H:i:s'));
        LiteAssert::same('2026-10-15 04:00:00', $tz->toLocal('2026-10-15 00:00:00', 2)->format('Y-m-d H:i:s'));
        LiteAssert::same('2026-07-01 02:00:00', $tz->toLocal('2026-07-01 00:00:00', 3)->format('Y-m-d H:i:s'), 'Berlin summer +2');
        LiteAssert::same('2026-12-01 01:00:00', $tz->toLocal('2026-12-01 00:00:00', 3)->format('Y-m-d H:i:s'), 'Berlin winter +1');
        foreach ([1, 2, 3] as $org) {
            LiteAssert::same('2026-10-15 00:00:00', $tz->toStorage($tz->toLocal('2026-10-15 00:00:00', $org)->format('Y-m-d H:i:s'), $org));
        }
        LiteAssert::same('2026-10-14 20:30:00', $tz->toStorage('2026-10-15 00:00:00', 1), 'Tehran midnight in UTC');
        LiteAssert::same('2026-10-15 00:00:00', $tz->toStorage(new \DateTimeImmutable('2026-10-15 04:00:00', new \DateTimeZone('Asia/Baku'))));
        LiteAssert::same(null, $tz->toLocal(null, 1));
        LiteAssert::same(null, $tz->toLocal('', 1));
        LiteAssert::same(null, $tz->toLocal('0000-00-00 00:00:00', 1));
        LiteAssert::same(null, $tz->toLocal('not a date', 1));
    },

    'TZ3 today / yesterday / midnight boundaries follow the organization, not the server' => function () use ($resolver) {
        // 20:29:59 UTC = 23:59:59 Tehran (still the 14th); one second later it is the 15th in Tehran.
        LiteAssert::same('2026-10-14', $resolver([1 => 'Asia/Tehran'], '2026-10-14 20:29:59')->today(1));
        LiteAssert::same('2026-10-15', $resolver([1 => 'Asia/Tehran'], '2026-10-14 20:30:00')->today(1));
        // Baku is +04:00: at 20:00 UTC it is already the 15th there, not in UTC.
        $tz = $resolver([1 => 'Asia/Tehran', 2 => 'Asia/Baku'], '2026-10-14 20:00:00');
        LiteAssert::same(['2026-10-14', '2026-10-15'], [$tz->today(1), $tz->today(2)]);
        LiteAssert::same('2026-10-14 23:30:00', $tz->localNow(1));

        $tz = $resolver([1 => 'Asia/Tehran', 2 => 'Asia/Baku']);
        LiteAssert::same(['2026-10-14 20:30:00', '2026-10-15 20:30:00'], $tz->dayRange('2026-10-15', 1), 'Tehran day');
        LiteAssert::same(['2026-10-14 20:00:00', '2026-10-15 20:00:00'], $tz->dayRange('2026-10-15', 2), 'Baku day');
        LiteAssert::same('2026-10-14 20:30:00', $tz->daysAgoStart(1, 1), 'last 1 day = today');
        LiteAssert::same('2026-10-08 20:30:00', $tz->daysAgoStart(7, 1), 'last 7 days = today and the 6 before');
        LiteAssert::throws(\InvalidArgumentException::class, fn () => $tz->dayRange('2026-02-30', 1));
    },

    'TZ4 DST: 23- and 25-hour days, and the clock change itself' => function () use ($resolver) {
        $tz = $resolver([1 => 'Europe/Berlin', 2 => 'America/New_York']);
        LiteAssert::same(['2026-03-28 23:00:00', '2026-03-29 22:00:00'], $tz->dayRange('2026-03-29', 1), 'spring: 23 h');
        LiteAssert::same(['2026-10-24 22:00:00', '2026-10-25 23:00:00'], $tz->dayRange('2026-10-25', 1), 'autumn: 25 h');
        LiteAssert::same(['2026-03-08 05:00:00', '2026-03-09 04:00:00'], $tz->dayRange('2026-03-08', 2), 'New York spring: 23 h');
        LiteAssert::same('2026-03-29 01:59:59', $tz->toLocal('2026-03-29 00:59:59', 1)->format('Y-m-d H:i:s'));
        LiteAssert::same('2026-03-29 03:00:00', $tz->toLocal('2026-03-29 01:00:00', 1)->format('Y-m-d H:i:s'), '02:00 local does not exist');
        LiteAssert::same('+01:00', OrganizationTimezone::offset(new \DateTimeZone('Europe/Berlin'), new \DateTimeImmutable('2026-01-15')));
        LiteAssert::same('+02:00', OrganizationTimezone::offset(new \DateTimeZone('Europe/Berlin'), new \DateTimeImmutable('2026-07-15')));
        // "Last 7 days" across the spring change is still 7 local midnights back.
        $spring = $resolver([1 => 'Europe/Berlin'], '2026-04-01 10:00:00');
        LiteAssert::same('2026-03-25 23:00:00', $spring->daysAgoStart(7, 1));
    },

    'TZ5 validation: only canonical IANA identifiers; invalid stored values and no organization fall back to the default' => function () use ($resolver) {
        foreach (['Asia/Tehran', 'Asia/Baku', 'Europe/Istanbul', 'UTC', 'America/New_York'] as $ok) {
            LiteAssert::true(OrganizationTimezone::isValid($ok), $ok);
        }
        foreach (['', ' ', '+03:30', 'GMT+3:30', 'UTC+3', 'asia/tehran', 'Asia/Teheran', 'Iran', 'Tehran', null, 3.5, ['Asia/Tehran']] as $bad) {
            LiteAssert::false(OrganizationTimezone::isValid($bad), json_encode($bad));
        }
        $tz = $resolver([1 => '+03:30', 2 => '', 3 => 'Asia/Baku']);
        LiteAssert::same('Asia/Tehran', $tz->forOrganization(1)->getName());
        LiteAssert::same('Asia/Tehran', $tz->forOrganization(2)->getName());
        LiteAssert::same('Asia/Tehran', $tz->forOrganization(404)->getName(), 'unknown organization');
        $previous = OrganizationContext::has() ? OrganizationContext::id() : null;
        OrganizationContext::reset();
        try {
            LiteAssert::same('Asia/Tehran', $tz->current()->getName(), 'no organization (Super Admin): default');
        } finally {
            if ($previous !== null) {
                OrganizationContext::set($previous);
            }
        }
        $choices = OrganizationTimezone::choices(new \DateTimeImmutable('2026-10-15'));
        LiteAssert::same('(UTC+03:30) Asia/Tehran', $choices['Asia']['Asia/Tehran']);
        LiteAssert::same('(UTC+04:00) Asia/Baku', $choices['Asia']['Asia/Baku']);
        LiteAssert::same('(UTC+00:00) UTC', $choices['UTC']['UTC']);
    },

    // --- Integration (crm_local, rolled back) ---------------------------------------

    'TZ6 settings: valid zone saved; invalid / offset rejected with nothing changed; empty keeps the zone' => function () use ($boot) {
        $a = $boot();
        $org = $a->pdo->query("SELECT name, default_timezone FROM organizations WHERE id = {$a->orgId}")->fetch();
        $post = fn (string $zone) => $a->request('POST', '/org/settings', [], ['name' => $org['name'], 'default_language' => 'en', 'default_timezone' => $zone]);
        $zone = fn () => $a->pdo->query("SELECT default_timezone FROM organizations WHERE id = {$a->orgId}")->fetchColumn();

        $post('Asia/Tehran');
        LiteAssert::same('Asia/Tehran', OrganizationTimezone::shared()->current($a->orgId)->getName(), 'resolver has read (and memoized) the old zone');
        LiteAssert::same(302, $post('Asia/Baku')['status']);
        LiteAssert::same('Asia/Baku', $zone());
        LiteAssert::same('Asia/Baku', OrganizationTimezone::shared()->current($a->orgId)->getName(), 'resolver sees the change at once');

        foreach (['+03:30', 'GMT+3:30', 'Mars/Olympus', 'Asia/Tehran; DROP TABLE organizations'] as $bad) {
            $r = $post($bad);
            LiteAssert::same(422, $r['status'], $bad);
            LiteAssert::contains('Choose a valid time zone from the list.', html_entity_decode($r['body'], ENT_QUOTES));
            LiteAssert::same('Asia/Baku', $zone(), "unchanged after {$bad}");
        }
        LiteAssert::same(302, $post('')['status']);
        LiteAssert::same('Asia/Baku', $zone(), 'empty keeps the current zone');

        $page = $a->request('GET', '/org/settings')['body'];
        LiteAssert::contains('<select id="default_timezone" name="default_timezone"', $page);
        LiteAssert::contains('<option value="Asia/Baku" selected>(UTC+04:00) Asia/Baku</option>', $page);
        LiteAssert::contains('<optgroup label="Europe">', $page);
        LiteAssert::notContains('type="text" id="default_timezone"', $page);
        $post('Asia/Tehran');
    },

    'TZ7 tenant isolation: a user changes only their own organization\'s zone' => function () use ($boot) {
        $a = $boot();
        if ($a->otherOrgId === null) {
            throw new LiteSkip('needs a second organization');
        }
        $a->pdo->exec("UPDATE organizations SET default_timezone = 'Europe/Istanbul' WHERE id = {$a->otherOrgId}");
        $name = $a->pdo->query("SELECT name FROM organizations WHERE id = {$a->orgId}")->fetchColumn();
        $a->request('POST', '/org/settings', [], ['name' => $name, 'default_language' => 'en', 'default_timezone' => 'Asia/Baku', 'organization_id' => (string) $a->otherOrgId, 'id' => (string) $a->otherOrgId]);
        LiteAssert::same('Europe/Istanbul', $a->pdo->query("SELECT default_timezone FROM organizations WHERE id = {$a->otherOrgId}")->fetchColumn(), 'other organization untouched');
        LiteAssert::same('Asia/Baku', $a->pdo->query("SELECT default_timezone FROM organizations WHERE id = {$a->orgId}")->fetchColumn());

        // Service level: acting on another organization's id is refused.
        LiteAssert::throws(\Throwable::class, fn () => $a->container()->make(OrganizationService::class)->update($a->otherOrgId, $a->admin, ['name' => 'x', 'default_timezone' => 'UTC']));
        LiteAssert::same('Europe/Istanbul', $a->pdo->query("SELECT default_timezone FROM organizations WHERE id = {$a->otherOrgId}")->fetchColumn());

        // A member without organization.edit cannot change it at all.
        if ($a->member !== null) {
            $a->as($a->member);
            $outcome = $a->outcome('POST', '/org/settings', [], ['name' => $name, 'default_timezone' => 'UTC']);
            LiteAssert::true(in_array($outcome, ['forbidden', 'notfound'], true), $outcome);
            $a->as($a->admin);
            LiteAssert::same('Asia/Baku', $a->pdo->query("SELECT default_timezone FROM organizations WHERE id = {$a->orgId}")->fetchColumn());
        }
        OrganizationTimezone::shared()->forget();
    },

    'TZ8 new organizations get Asia/Tehran; existing ones all hold a valid zone after migration 0084' => function () use ($boot) {
        $a = $boot();
        $id = $a->container()->make(OrganizationService::class)->create('LiteTZ New Org');
        LiteAssert::same('Asia/Tehran', $a->pdo->query("SELECT default_timezone FROM organizations WHERE id = {$id}")->fetchColumn());
        $a->pdo->exec("INSERT INTO organizations (name, slug) VALUES ('LiteTZ Raw', 'litetz-raw-" . bin2hex(random_bytes(3)) . "')");
        LiteAssert::same('Asia/Tehran', $a->pdo->query('SELECT default_timezone FROM organizations WHERE id = ' . (int) $a->pdo->lastInsertId())->fetchColumn(), 'column default');
        foreach ($a->pdo->query('SELECT id, default_timezone FROM organizations')->fetchAll(\PDO::FETCH_KEY_PAIR) as $org => $zone) {
            LiteAssert::true(OrganizationTimezone::isValid($zone), "organization {$org}: {$zone}");
        }
    },

    'TZ9 UTC storage: the session is +00:00; CURRENT_TIMESTAMP and ON UPDATE write UTC like PHP' => function () use ($boot) {
        $a = $boot();
        if ($a->pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'storage_timezone_state'")->fetchColumn() == 0) {
            throw new LiteSkip('migration 0083 not applied to crm_local');
        }
        LiteAssert::same('+00:00', $a->pdo->query('SELECT @@session.time_zone')->fetchColumn());
        LiteAssert::true(Connection::applyStorageTimezone($a->pdo));
        $drift = fn (string $value) => abs(strtotime($value . ' UTC') - time());
        LiteAssert::true($drift((string) $a->pdo->query('SELECT NOW()')->fetchColumn()) <= 5, 'NOW() is UTC');

        $a->pdo->prepare("INSERT INTO contacts (organization_id, first_name, status, updated_at) VALUES (?, 'LiteTZ', 'active', '2020-01-01 00:00:00')")->execute([$a->orgId]);
        $id = (int) $a->pdo->lastInsertId();
        $row = $a->pdo->query("SELECT created_at FROM contacts WHERE id = {$id}")->fetch();
        LiteAssert::true($drift($row['created_at']) <= 5, 'CURRENT_TIMESTAMP is UTC: ' . $row['created_at']);
        $a->pdo->exec("UPDATE contacts SET first_name = 'LiteTZ2' WHERE id = {$id}");
        LiteAssert::true($drift((string) $a->pdo->query("SELECT updated_at FROM contacts WHERE id = {$id}")->fetchColumn()) <= 5, 'ON UPDATE is UTC');
        LiteAssert::true($drift(date('Y-m-d H:i:s')) <= 5, 'PHP date() is UTC');
    },

    'TZ10 display: the same stored instant appears in each organization\'s local time' => function () use ($boot) {
        $a = $boot();
        if ($a->otherOrgId === null) {
            throw new LiteSkip('needs a second organization');
        }
        $a->pdo->exec("UPDATE organizations SET default_timezone = 'Asia/Tehran' WHERE id = {$a->orgId}");
        $a->pdo->exec("UPDATE organizations SET default_timezone = 'Asia/Baku' WHERE id = {$a->otherOrgId}");
        OrganizationTimezone::shared()->forget();
        LiteAssert::same('2026-10-15 03:30:00', local_time('2026-10-15 00:00:00'), 'current organization: Tehran');
        LiteAssert::same('2026-10-15 04:00:00', OrganizationContext::runAs($a->otherOrgId, fn () => local_time('2026-10-15 00:00:00')), 'Baku');
        LiteAssert::same(null, local_time(null));

        // Through a real page: the opt-out list shows created_at in the organization's time.
        $a->pdo->exec("DELETE FROM modules WHERE `key` IN ('marketing', 'marketing_email')");
        $a->pdo->exec("INSERT INTO modules (`key`, name, version, type, is_core, status, dependencies) VALUES ('marketing', 'marketing', '1.0.0', 'addon', 0, 'active', '[]')");
        $a->pdo->prepare('INSERT INTO organization_modules (organization_id, module_id, enabled) VALUES (?, ?, 1)')->execute([$a->orgId, (int) $a->pdo->lastInsertId()]);
        $a->pdo->prepare("INSERT INTO marketing_suppressions (organization_id, channel, address, reason, source, created_at) VALUES (?, 'email', 'litetz@example.test', 'manual', 'user', '2026-10-15 00:00:00')")->execute([$a->orgId]);
        $body = $a->request('GET', '/marketing/suppressions', ['q' => 'litetz@'])['body'];
        LiteAssert::contains('2026-10-15 03:30:00', $body);
        LiteAssert::notContains('2026-10-15 00:00:00', $body);
        $a->pdo->exec("UPDATE organizations SET default_timezone = 'Asia/Baku' WHERE id = {$a->orgId}");
        OrganizationTimezone::shared()->forget();
        LiteAssert::contains('2026-10-15 04:00:00', $a->request('GET', '/marketing/suppressions', ['q' => 'litetz@'])['body']);
        $a->pdo->exec("UPDATE organizations SET default_timezone = 'Asia/Tehran' WHERE id = {$a->orgId}");
        OrganizationTimezone::shared()->forget();
    },

    'TZ11 dashboard: "due today" / "overdue" use the organization\'s today (local due dates, never converted)' => function () use ($boot) {
        $a = $boot();
        $r = $a->request('POST', '/tasks', [], ['title' => 'LiteTZ dash task', 'due_at' => '2026-10-15', 'assigned_to_user_ids' => [(string) $a->admin['id']], 'return_to' => '/tasks']);
        if ($r['status'] !== 302) {
            throw new LiteSkip('Sales / tasks not available locally (HTTP ' . $r['status'] . ')');
        }
        $a->pdo->exec("UPDATE organizations SET default_timezone = 'Asia/Tehran' WHERE id = {$a->orgId}");
        $groupOf = function (string $utcNow) use ($a): ?string {
            $pdo = $a->pdo;
            OrganizationTimezone::setShared(new OrganizationTimezone(function (int $id) use ($pdo) {
                return $pdo->query('SELECT default_timezone FROM organizations WHERE id = ' . $id)->fetchColumn() ?: null;
            }, 'Asia/Tehran', fn () => new \DateTimeImmutable($utcNow, new \DateTimeZone('UTC'))));
            $body = $a->request('GET', '/')['body'];
            $parts = explode('task-group__title', $body);
            foreach (['overdue' => 1, 'due_today' => 2, 'upcoming' => 3] as $group => $i) {
                if (isset($parts[$i]) && str_contains(explode('task-group__title', $parts[$i])[0], 'LiteTZ dash task')) {
                    return $group;
                }
            }

            return null;
        };
        try {
            // 20:29 UTC on the 14th = 23:59 in Tehran: the task (due the 15th) is upcoming; at 20:31 UTC it is due today.
            LiteAssert::same('upcoming', $groupOf('2026-10-14 20:29:00'));
            LiteAssert::same('due_today', $groupOf('2026-10-14 20:31:00'));
            LiteAssert::same('due_today', $groupOf('2026-10-15 20:29:00'));
            LiteAssert::same('overdue', $groupOf('2026-10-15 20:31:00'), 'past midnight in Tehran, still the 15th in UTC');
            LiteAssert::same('2026-10-15', substr((string) $a->pdo->query("SELECT due_at FROM tasks WHERE title = 'LiteTZ dash task'")->fetchColumn(), 0, 10), 'due date stored as typed');
        } finally {
            OrganizationTimezone::setShared(null);
        }
    },

    // --- Migrations 0083 / 0084 on a disposable database --------------------------------

    'TZ12 migration 0083: converts only verified C1 columns; C2/C3 kept; ON UPDATE not fired; rerun safe; down exact' => function () {
        $root = dirname(__DIR__, 2);
        $db = 'crm_tz_test_' . bin2hex(random_bytes(3));
        $cfg = \App\Infrastructure\Config\Config::get('database.connections.mysql');
        try {
            $server = new \PDO(sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $cfg['host'], $cfg['port']), $cfg['username'], $cfg['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $server->exec("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4");
        } catch (\Throwable $e) {
            throw new LiteSkip('cannot create a disposable database: ' . $e->getMessage());
        }
        try {
            $pdo = new \PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $cfg['host'], $cfg['port'], $db), $cfg['username'], $cfg['password'], [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC, \PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            // Fixture values are explicit literals; the session zone only matters for the migration below.
            $pdo->exec("SET time_zone = '+03:30'");
            // Before 0083 (no state table): the connection keeps its zone.
            LiteAssert::false(Connection::applyStorageTimezone($pdo), 'missing state table = not converted');
            LiteAssert::same('+03:30', $pdo->query('SELECT @@session.time_zone')->fetchColumn());
            $pdo->exec("CREATE TABLE tasks (id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(50),
                due_at DATETIME NULL, completed_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB");
            $pdo->exec("CREATE TABLE contact_import_batch_items (id INT AUTO_INCREMENT PRIMARY KEY, stamp DATETIME NULL) ENGINE=InnoDB");
            $pdo->exec("CREATE TABLE marketing_campaigns (id INT AUTO_INCREMENT PRIMARY KEY, audience_snapshot TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB");
            $pdo->exec("CREATE TABLE zz_unlisted (id INT AUTO_INCREMENT PRIMARY KEY, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
            $pdo->exec("CREATE TABLE migrations (id INT AUTO_INCREMENT PRIMARY KEY, migration VARCHAR(255), applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
            $pdo->exec("CREATE TABLE organizations (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(50), default_timezone VARCHAR(64) NOT NULL DEFAULT 'UTC',
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB");

            $pdo->exec("INSERT INTO tasks (title, due_at, completed_at, created_at, updated_at) VALUES
                ('a', '2026-10-20 09:00:00', '2026-10-15 08:30:00', '2026-10-15 12:00:00', '2026-10-15 12:00:00'),
                ('b', NULL, NULL, '2026-03-21 00:10:00', '2026-03-21 00:10:00')");
            $pdo->exec("INSERT INTO contact_import_batch_items (stamp) VALUES ('2026-10-15 12:00:00')");
            $pdo->exec("INSERT INTO marketing_campaigns (audience_snapshot, created_at, updated_at) VALUES ('{\"segment_id\":1,\"type\":\"static\",\"cutoff\":\"2026-10-15 12:00:00\"}', '2026-10-15 12:00:00', '2026-10-15 12:00:00')");
            $pdo->exec("INSERT INTO zz_unlisted (created_at) VALUES ('2026-10-15 12:00:00')");
            $pdo->exec("INSERT INTO organizations (name, default_timezone) VALUES ('o1', 'UTC'), ('o2', 'Europe/Istanbul'), ('o3', '+03:30'), ('o4', '')");
            $pdo->exec("UPDATE organizations SET updated_at = '2026-01-01 00:00:00'");
            $snapshotOf = fn () => [
                $pdo->query('SELECT title, due_at, completed_at, created_at, updated_at FROM tasks ORDER BY id')->fetchAll(),
                $pdo->query('SELECT stamp FROM contact_import_batch_items')->fetchAll(),
                $pdo->query('SELECT audience_snapshot, created_at, updated_at FROM marketing_campaigns')->fetchAll(),
                $pdo->query('SELECT created_at FROM zz_unlisted')->fetchAll(),
                $pdo->query('SELECT name, default_timezone, updated_at FROM organizations ORDER BY id')->fetchAll(),
            ];
            $before = $snapshotOf();

            // The migration reads the legacy zone from @@global; on this test server that is the server's own
            // zone, so derive the expected UTC values from the same offset the migration will use.
            $pdo->exec('SET time_zone = @@global.time_zone');
            $offset = (int) $pdo->query('SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW())')->fetchColumn();
            $utc = fn (string $v) => date('Y-m-d H:i:s', strtotime($v . ' UTC') - $offset);

            $m83 = require $root . '/database/migrations/0083_convert_timestamps_to_utc_storage.php';
            $m84 = require $root . '/database/migrations/0084_normalize_organization_timezones.php';
            $m83->up($pdo);

            $tasks = $pdo->query('SELECT * FROM tasks ORDER BY id')->fetchAll();
            LiteAssert::same($utc('2026-10-15 12:00:00'), $tasks[0]['created_at'], 'C1 created_at converted');
            LiteAssert::same($utc('2026-10-15 12:00:00'), $tasks[0]['updated_at'], 'C1 updated_at converted, ON UPDATE did not fire');
            LiteAssert::same('2026-10-15 08:30:00', $tasks[0]['completed_at'], 'C2 (PHP, already UTC) untouched');
            LiteAssert::same('2026-10-20 09:00:00', $tasks[0]['due_at'], 'C3 (user local time) untouched');
            LiteAssert::same($utc('2026-03-21 00:10:00'), $tasks[1]['created_at'], 'conversion across a date change');
            LiteAssert::same($utc('2026-10-15 12:00:00'), $pdo->query('SELECT stamp FROM contact_import_batch_items')->fetchColumn(), 'derived stamp converted with updated_at');
            LiteAssert::same($utc('2026-10-15 12:00:00'), json_decode((string) $pdo->query('SELECT audience_snapshot FROM marketing_campaigns')->fetchColumn(), true)['cutoff'], 'snapshot cutoff converted');
            LiteAssert::same($utc('2026-10-15 12:00:00'), $pdo->query('SELECT updated_at FROM marketing_campaigns')->fetchColumn(), 'rewriting the snapshot did not fire ON UPDATE');
            LiteAssert::same('2026-10-15 12:00:00', $pdo->query('SELECT created_at FROM zz_unlisted')->fetchColumn(), 'unverified column left as is');
            $state = $pdo->query('SELECT * FROM storage_timezone_state')->fetch();
            LiteAssert::same(['zz_unlisted.created_at'], json_decode($state['unverified_columns'], true));
            LiteAssert::same('+00:00', $pdo->query('SELECT @@session.time_zone')->fetchColumn(), 'session switched to UTC');

            // After the switch: new rows and ON UPDATE are UTC.
            $pdo->exec("INSERT INTO tasks (title) VALUES ('new')");
            $new = $pdo->query("SELECT created_at, UTC_TIMESTAMP() AS u FROM tasks WHERE title = 'new'")->fetch();
            LiteAssert::true(abs(strtotime($new['created_at']) - strtotime($new['u'])) <= 5, 'CURRENT_TIMESTAMP is UTC after the switch');
            $pdo->exec("UPDATE tasks SET title = 'a2' WHERE title = 'a'");
            LiteAssert::true(abs(strtotime((string) $pdo->query("SELECT updated_at FROM tasks WHERE title = 'a2'")->fetchColumn()) - time()) <= 5, 'ON UPDATE is UTC');
            $pdo->exec("UPDATE tasks SET title = 'a', updated_at = '" . $utc('2026-10-15 12:00:00') . "' WHERE title = 'a2'");
            $pdo->exec("DELETE FROM tasks WHERE title = 'new'");

            // Rerun: nothing changes.
            $converted = $snapshotOf();
            $m83->up($pdo);
            LiteAssert::same($converted, $snapshotOf(), '0083 rerun is a no-op');

            // 0084: UTC / empty / invalid -> Asia/Tehran; explicit non-UTC kept; updated_at kept; rerun safe.
            $m84->up($pdo);
            $m84->up($pdo);
            LiteAssert::same(['Asia/Tehran', 'Europe/Istanbul', 'Asia/Tehran', 'Asia/Tehran'], array_column($pdo->query('SELECT default_timezone FROM organizations ORDER BY id')->fetchAll(), 'default_timezone'));
            LiteAssert::same([$utc('2026-01-01 00:00:00')], array_values(array_unique(array_column($pdo->query('SELECT updated_at FROM organizations')->fetchAll(), 'updated_at'))));
            $m84->down($pdo);

            // Down restores the original values exactly (0084 first, then 0083).
            $m83->down($pdo);
            LiteAssert::same($before, $snapshotOf(), 'down restores every value');
            LiteAssert::same(0, (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'storage_timezone_state'")->fetchColumn());
        } finally {
            $server->exec("DROP DATABASE IF EXISTS `{$db}`");
        }
    },

    'TZ13 UTC session check: only "table does not exist" means not converted; any other error is never swallowed' => function () {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            throw new LiteSkip('pdo_sqlite not available');
        }
        // A driver error that is not MySQL 1146 / SQLSTATE 42S02 must propagate
        // (otherwise a converted database could silently get old-zone writes).
        $other = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        LiteAssert::throws(\PDOException::class, fn () => Connection::applyStorageTimezone($other));
    },

    'cleanup: roll back everything' => function () use ($h) {
        OrganizationTimezone::setShared(null);
        if ($h->app !== null) {
            $h->app->rollBack();
        }
        LiteAssert::true(true);
    },
];
