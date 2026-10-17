<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Organization time zone (docs/organization-timezone-design.md, D1).
 *
 * organizations.default_timezone existed but was never read, and the
 * settings form re-posted its pre-filled "UTC" on every save — so "UTC",
 * empty and invalid values were never a real choice: they become
 * Asia/Tehran. A valid IANA zone other than UTC is an explicit choice and
 * is kept. New organizations default to Asia/Tehran (column default; the
 * application also sets it explicitly from config).
 *
 * updated_at is kept (a data fix, not an edit by the organization).
 * Re-running is harmless (only invalid/UTC values change). down() restores
 * the column default 'UTC' and the values recorded as changed here.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec("ALTER TABLE organizations MODIFY default_timezone VARCHAR(64) NOT NULL DEFAULT 'Asia/Tehran'");
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS organization_timezone_normalized (
                organization_id INT UNSIGNED NOT NULL PRIMARY KEY,
                previous_value VARCHAR(64) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $valid = \DateTimeZone::listIdentifiers();
        $log = $pdo->prepare('INSERT IGNORE INTO organization_timezone_normalized (organization_id, previous_value) VALUES (?, ?)');
        $fix = $pdo->prepare("UPDATE organizations SET default_timezone = 'Asia/Tehran', updated_at = updated_at WHERE id = ?");
        foreach ($pdo->query('SELECT id, default_timezone FROM organizations')->fetchAll(\PDO::FETCH_ASSOC) as $org) {
            $value = trim((string) $org['default_timezone']);
            if ($value === 'UTC' || !in_array($value, $valid, true)) {
                $log->execute([(int) $org['id'], (string) $org['default_timezone']]);
                $fix->execute([(int) $org['id']]);
            }
        }
    }

    public function down(\PDO $pdo): void
    {
        $exists = $pdo->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'organization_timezone_normalized'")->fetchColumn();
        if ($exists !== false) {
            $pdo->exec(
                "UPDATE organizations o JOIN organization_timezone_normalized n ON n.organization_id = o.id
                 SET o.default_timezone = n.previous_value, o.updated_at = o.updated_at WHERE o.default_timezone = 'Asia/Tehran'"
            );
            $pdo->exec('DROP TABLE organization_timezone_normalized');
        }
        $pdo->exec("ALTER TABLE organizations MODIFY default_timezone VARCHAR(64) NOT NULL DEFAULT 'UTC'");
    }
};
