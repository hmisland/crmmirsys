<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Enables an atomic "claim" step in WalletTopUpService::handleCallback():
 * a single conditional UPDATE (status='pending' -> 'processing') is
 * where the actual mutual exclusion lives — MySQL guarantees only ONE
 * concurrent request can win that UPDATE for a given row, so if the
 * gateway's own redirect is somehow hit twice at nearly the same
 * instant (a double-click, a browser retry), only one request
 * proceeds to call the gateway's verify API and credit the wallet;
 * the other sees 0 rows affected and returns "already being
 * processed" without touching anything.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec("ALTER TABLE wallet_transactions MODIFY COLUMN status ENUM('pending', 'processing', 'completed', 'failed') NOT NULL DEFAULT 'pending'");
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec("UPDATE wallet_transactions SET status = 'pending' WHERE status = 'processing'");
        $pdo->exec("ALTER TABLE wallet_transactions MODIFY COLUMN status ENUM('pending', 'completed', 'failed') NOT NULL DEFAULT 'pending'");
    }
};
