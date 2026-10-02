<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

/**
 * Applies migration files from a directory, in filename order, tracking
 * what has run in a small ledger table.
 *
 * Used two ways in this architecture (per Phase 0 §31):
 *  - Core migrations: new self($pdo, 'database/migrations', 'migrations')
 *  - A module's own migrations (from Phase 4 onward, once the Module
 *    Manager exists): new self($pdo, 'modules/Sales/Migrations', 'module_migrations', 'sales')
 *
 * Each migration file must `return` a class implementing MigrationInterface.
 * PDO referenced as \PDO — see Connection.php's docblock for why plain
 * `use PDO;` is deliberately avoided.
 */
final class Migrator
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $path,
        private readonly string $trackingTable = 'migrations',
        private readonly ?string $moduleKey = null,
    ) {
        $this->ensureTrackingTableExists();
    }

    public function run(): array
    {
        $applied = [];
        $files = glob(rtrim($this->path, '/') . '/*.php') ?: [];
        sort($files);

        foreach ($files as $file) {
            $migrationName = basename($file, '.php');

            if ($this->hasRun($migrationName)) {
                continue;
            }

            /** @var MigrationInterface $migration */
            $migration = require $file;

            // Deliberately NOT wrapped in an explicit PDO transaction:
            // MySQL/MariaDB implicitly commits on every DDL statement
            // (CREATE TABLE, etc.), which silently ends a transaction
            // started with beginTransaction() — a later explicit
            // commit()/rollBack() call then has nothing to act on and
            // throws. Migrations here are DDL, so there is no
            // transaction for MySQL to actually honor anyway.
            try {
                $migration->up($this->pdo);
                $this->recordApplied($migrationName);
                $applied[] = $migrationName;
            } catch (\Throwable $e) {
                throw new \RuntimeException("Migration failed: {$migrationName}", 0, $e);
            }
        }

        return $applied;
    }

    private function ensureTrackingTableExists(): void
    {
        if ($this->moduleKey !== null) {
            $this->pdo->exec(
                "CREATE TABLE IF NOT EXISTS {$this->trackingTable} (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    module_key VARCHAR(100) NOT NULL,
                    migration VARCHAR(255) NOT NULL,
                    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uniq_module_migration (module_key, migration)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        } else {
            $this->pdo->exec(
                "CREATE TABLE IF NOT EXISTS {$this->trackingTable} (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    migration VARCHAR(255) NOT NULL UNIQUE,
                    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        }
    }

    private function hasRun(string $migrationName): bool
    {
        if ($this->moduleKey !== null) {
            $stmt = $this->pdo->prepare(
                "SELECT 1 FROM {$this->trackingTable} WHERE module_key = :module_key AND migration = :migration"
            );
            $stmt->execute(['module_key' => $this->moduleKey, 'migration' => $migrationName]);
        } else {
            $stmt = $this->pdo->prepare("SELECT 1 FROM {$this->trackingTable} WHERE migration = :migration");
            $stmt->execute(['migration' => $migrationName]);
        }

        return (bool) $stmt->fetchColumn();
    }

    private function recordApplied(string $migrationName): void
    {
        if ($this->moduleKey !== null) {
            $stmt = $this->pdo->prepare(
                "INSERT INTO {$this->trackingTable} (module_key, migration) VALUES (:module_key, :migration)"
            );
            $stmt->execute(['module_key' => $this->moduleKey, 'migration' => $migrationName]);
        } else {
            $stmt = $this->pdo->prepare("INSERT INTO {$this->trackingTable} (migration) VALUES (:migration)");
            $stmt->execute(['migration' => $migrationName]);
        }
    }
}
