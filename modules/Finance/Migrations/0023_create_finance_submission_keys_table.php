<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Submission (idempotency) keys for financial writes — Stage 5.2, finding L1
 * (decisions D1 / D2). Same precedent as Wallet 0003: a database UNIQUE key is
 * the guard, because only the database serializes two concurrent requests.
 *
 * Every create form (expense, payment, credit note) carries a random 128-bit
 * key. Inside the SAME transaction that writes the financial record, the key
 * row is inserted first ("claimed") and then pointed at the new record:
 * - a repeat of the same request (double click, browser retry, a second
 *   process) hits the UNIQUE key — it waits while the first is in flight, then
 *   gets "duplicate" and is answered with the ORIGINAL record; nothing new is
 *   written, so no duplicate record and no duplicate audit event;
 * - if the first transaction fails, its key row is rolled back with it, so a
 *   valid retry is never blocked;
 * - two separate forms have two different keys, so legitimate identical
 *   transactions are still allowed.
 *
 * Scope: per organization and operation (scope); user_id is kept to answer a
 * repeat only for the same user. Retention: rows are kept for the lifetime of
 * the record they point at (no automatic purge — an approved retention policy
 * would be a separate change).
 *
 * Purely additive. down() drops ONLY this table: the financial records stay
 * intact; it merely removes duplicate-submission protection (forms posting a
 * key would then fail until the table exists again — deploy code and schema
 * together, roll back together).
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE finance_submission_keys (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NULL,
                scope VARCHAR(30) NOT NULL,
                submission_key CHAR(32) NOT NULL,
                resource_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_finance_submission_keys_key (organization_id, scope, submission_key),
                KEY idx_finance_submission_keys_resource (organization_id, scope, resource_id),
                CONSTRAINT fk_finance_submission_keys_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_finance_submission_keys_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS finance_submission_keys');
    }
};
