<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Every reply is authored by an internal user (no customer portal yet
 * — see tickets migration's docblock). `is_internal` distinguishes a
 * note meant only for the team (never shown to/sent to the customer)
 * from a reply meant for the customer — the latter is where a future
 * "email this to the contact" integration would hook in; for now both
 * kinds just live in the same thread, visually distinguished.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE ticket_replies (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                ticket_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NULL,
                body TEXT NOT NULL,
                is_internal TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_ticket_replies_ticket (ticket_id, id),
                CONSTRAINT fk_ticket_replies_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_ticket_replies_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS ticket_replies');
    }
};
