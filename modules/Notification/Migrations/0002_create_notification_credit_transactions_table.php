<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE notification_credit_transactions (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                type ENUM('topup', 'usage', 'refund') NOT NULL,
                amount DECIMAL(38,18) NOT NULL,
                balance_after DECIMAL(38,18) NOT NULL,
                reference_type VARCHAR(50) NULL,
                reference_id BIGINT UNSIGNED NULL,
                description VARCHAR(255) NULL,
                created_by_user_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_notification_credit_tx_organization (organization_id, id),
                CONSTRAINT fk_notification_credit_tx_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS notification_credit_transactions');
    }
};
