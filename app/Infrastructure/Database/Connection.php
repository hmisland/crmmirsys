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
        }

        return self::$pdo;
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
