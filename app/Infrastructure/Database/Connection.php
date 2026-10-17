<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use App\Infrastructure\Config\Config;

/**
 * Lazy PDO singleton. Every Repository (see BaseRepository) goes through
 * this — nothing in the application opens its own connection or builds
 * raw SQL strings outside a prepared statement.
 *
 * PDO/PDOException are referenced fully-qualified (\PDO, \PDOException)
 * rather than imported via `use PDO;` — PHP emits a compiler warning
 * for `use`-importing a non-namespaced (global) class like this
 * ("The use statement with non-compound name 'PDO' has no effect"),
 * which used to get escalated into a fatal error by ErrorHandler. Both
 * are fixed now (see ErrorHandler's docblock), but avoiding the import
 * entirely removes the warning at the source too.
 */
final class Connection
{
    private static ?\PDO $pdo = null;

    public static function pdo(): \PDO
    {
        if (self::$pdo === null) {
            self::$pdo = self::connect();
            self::applyStorageTimezone(self::$pdo);
        }

        return self::$pdo;
    }

    /**
     * UTC storage: once migration 0083 has converted the MySQL-stamped
     * timestamps (marker row in storage_timezone_state), every connection
     * runs with session time_zone +00:00, so CURRENT_TIMESTAMP, ON UPDATE
     * and NOW() are UTC like PHP's date(). An offset, not a named zone:
     * no MySQL time-zone tables needed, no dependence on the host's zone.
     * Before the conversion (fresh install, or code uploaded but not yet
     * migrated) the server zone is kept, so no table ever mixes two zones.
     */
    public static function applyStorageTimezone(\PDO $pdo): bool
    {
        try {
            $converted = $pdo->query('SELECT 1 FROM storage_timezone_state WHERE id = 1')->fetchColumn() !== false;
        } catch (\PDOException $e) {
            // Only "table doesn't exist" (migration 0083 not run yet) means the
            // old zone. Any other error is rethrown: silently staying in the old
            // zone after the conversion would write mixed timestamps.
            if ($e->getCode() === '42S02' || (int) ($e->errorInfo[1] ?? 0) === 1146) {
                return false;
            }
            throw $e;
        }
        if ($converted) {
            $pdo->exec("SET time_zone = '+00:00'");
        }

        return $converted;
    }

    private static function connect(): \PDO
    {
        $config = Config::get('database.connections.' . Config::get('database.default'));

        $dsn = sprintf(
            '%s:host=%s;port=%s;dbname=%s;charset=%s',
            $config['driver'] ?? 'mysql',
            $config['host'] ?? '127.0.0.1',
            $config['port'] ?? '3306',
            $config['database'] ?? '',
            $config['charset'] ?? 'utf8mb4'
        );

        try {
            return new \PDO(
                $dsn,
                $config['username'] ?? '',
                $config['password'] ?? '',
                [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                    \PDO::ATTR_EMULATE_PREPARES => false, // real prepared statements
                    \PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES 'utf8mb4', sql_mode='STRICT_ALL_TABLES'",
                ]
            );
        } catch (\PDOException $e) {
            // Never leak DSN/credentials to the caller or an end user.
            // ErrorHandler is responsible for logging $e with full detail.
            throw new \RuntimeException('Database connection failed.', 0, $e);
        }
    }

    /**
     * For tests only: allows an already-configured PDO (e.g. sqlite
     * in-memory) to be injected instead of the real connection.
     */
    public static function swap(\PDO $pdo): void
    {
        self::$pdo = $pdo;
    }
}
