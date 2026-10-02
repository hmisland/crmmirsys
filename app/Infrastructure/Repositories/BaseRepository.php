<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Infrastructure\Database\Connection;

/**
 * Per Phase 0 §55/§58/§64: Repositories are the ONLY layer that writes
 * SQL. Services call Repositories; Repositories never call other
 * modules' Repositories directly (that goes through a Service or an
 * Event instead). Every query here uses a prepared statement — no
 * string interpolation of user input into SQL, ever.
 *
 * PDO/PDOStatement referenced as \PDO/\PDOStatement — see Connection.php's
 * docblock for why plain `use PDO;` is deliberately avoided.
 */
abstract class BaseRepository
{
    protected function pdo(): \PDO
    {
        return Connection::pdo();
    }

    protected function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt;
    }

    protected function findOne(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    protected function findMany(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    protected function insertGetId(string $table, array $columns): int
    {
        $fields = array_keys($columns);
        $placeholders = array_map(fn ($f) => ':' . $f, $fields);

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', array_map($this->quoteIdentifier(...), $fields)),
            implode(', ', $placeholders)
        );

        $this->query($sql, $columns);

        return (int) $this->pdo()->lastInsertId();
    }

    protected function update(string $table, array $columns, string $whereSql, array $whereParams): int
    {
        $set = implode(', ', array_map(
            fn ($f) => $this->quoteIdentifier($f) . " = :{$f}",
            array_keys($columns)
        ));
        $sql = "UPDATE {$table} SET {$set} WHERE {$whereSql}";

        return $this->query($sql, array_merge($columns, $whereParams))->rowCount();
    }

    protected function delete(string $table, string $whereSql, array $whereParams): int
    {
        return $this->query("DELETE FROM {$table} WHERE {$whereSql}", $whereParams)->rowCount();
    }

    /**
     * Backtick-quotes a column name unconditionally. Cheap insurance
     * against MySQL/MariaDB reserved words showing up as a genuinely
     * meaningful column name (e.g. `roles`.`key`, `modules`.`key`) —
     * without this, an INSERT/UPDATE touching such a column fails
     * with a SQL syntax error at the exact moment someone finally
     * exercises that code path, which is exactly what happened here
     * during Phase 4 testing.
     */
    private function quoteIdentifier(string $column): string
    {
        return '`' . str_replace('`', '``', $column) . '`';
    }

    /**
     * Public (not protected): a repository's own methods use this
     * for a single-repository transaction as before, but a Service
     * that needs to orchestrate a transaction spanning MORE than one
     * repository (e.g. Wallet's credit()/debit(), which locks a
     * wallet row via WalletRepository and writes a row via
     * WalletLedgerRepository in the same transaction) is not a
     * repository subclass and could never call a protected method on
     * an injected repository instance — this would otherwise be a
     * PHP fatal error the moment such a Service tried it.
     */
    public function transaction(callable $callback): mixed
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();

        try {
            $result = $callback();
            $pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
