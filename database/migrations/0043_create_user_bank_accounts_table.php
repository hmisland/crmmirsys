<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Lives in Core, not the Wallet module — a bank account is
 * fundamentally profile/identity data (must match the user's own
 * national ID and mobile phone, per the requirement), the same
 * reasoning that put mobile_phone/national_id directly on `users`
 * rather than in a module table. Wallet's withdrawal request flow
 * reads from this table (a module depending on Core is normal and
 * expected — Sales depends on CRM Core's Contacts the same way); it
 * never writes to it.
 *
 * `sheba_number` is stored WITHOUT the "IR" prefix (digits only, per
 * the explicit requirement) — the UI is responsible for showing it
 * with "IR" prepended if that's ever needed for display.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE user_bank_accounts (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                bank_name VARCHAR(100) NOT NULL,
                account_number VARCHAR(40) NULL,
                sheba_number VARCHAR(24) NULL,
                card_number VARCHAR(20) NULL,
                branch_code VARCHAR(20) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_user_bank_accounts_user (user_id),
                CONSTRAINT fk_user_bank_accounts_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_user_bank_accounts_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS user_bank_accounts');
    }
};
