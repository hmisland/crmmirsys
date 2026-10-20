<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Platform tickets: an organization admin writes to the platform team
 * (Super Admin) and both sides reply in one thread. Separate from the
 * Support module's `tickets` (an organization's own customer tickets).
 *
 * platform_tickets.status: open (waiting for the platform team) |
 * answered (the platform team replied, waiting for the organization) |
 * closed. priority: normal | high | urgent. category: a fixed list of keys.
 * last_activity_at orders the lists; messages are never edited or deleted.
 *
 * New tables only; rollback drops them.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE platform_tickets (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                created_by_user_id INT UNSIGNED NULL,
                subject VARCHAR(200) NOT NULL,
                category VARCHAR(30) NOT NULL DEFAULT 'general',
                priority VARCHAR(20) NOT NULL DEFAULT 'normal',
                status VARCHAR(20) NOT NULL DEFAULT 'open',
                last_activity_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                closed_at DATETIME NULL,
                closed_by_user_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_platform_tickets_status (status, last_activity_at),
                KEY idx_platform_tickets_org (organization_id, last_activity_at),
                KEY idx_platform_tickets_creator (created_by_user_id, created_at),
                CONSTRAINT fk_platform_tickets_org FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_platform_tickets_creator FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT fk_platform_tickets_closed_by FOREIGN KEY (closed_by_user_id) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $pdo->exec(
            "CREATE TABLE platform_ticket_messages (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                ticket_id INT UNSIGNED NOT NULL,
                author_user_id INT UNSIGNED NULL,
                author_side VARCHAR(20) NOT NULL,
                body TEXT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_platform_ticket_messages_ticket (ticket_id, id),
                CONSTRAINT fk_platform_ticket_messages_ticket FOREIGN KEY (ticket_id) REFERENCES platform_tickets (id) ON DELETE CASCADE,
                CONSTRAINT fk_platform_ticket_messages_author FOREIGN KEY (author_user_id) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS platform_ticket_messages');
        $pdo->exec('DROP TABLE IF EXISTS platform_tickets');
    }
};
