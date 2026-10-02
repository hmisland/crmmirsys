<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * `contact_id` is the requester — the customer this ticket is about,
 * an existing CRM Core Contact (no separate "requester" concept, and
 * no customer self-service portal yet, per Phase 0's own MVP scoping
 * — a ticket is always raised BY an internal user ON BEHALF OF a
 * contact, not submitted directly by the contact through a portal).
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE tickets (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                subject VARCHAR(255) NOT NULL,
                description TEXT NULL,
                status ENUM('open', 'pending', 'resolved', 'closed') NOT NULL DEFAULT 'open',
                priority ENUM('low', 'medium', 'high', 'urgent') NOT NULL DEFAULT 'medium',
                category_id INT UNSIGNED NULL,
                contact_id INT UNSIGNED NULL,
                assigned_to_user_id INT UNSIGNED NULL,
                created_by_user_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                resolved_at DATETIME NULL,
                closed_at DATETIME NULL,
                KEY idx_tickets_organization (organization_id),
                KEY idx_tickets_status (status),
                KEY idx_tickets_assigned (assigned_to_user_id),
                KEY idx_tickets_contact (contact_id),
                CONSTRAINT fk_tickets_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_tickets_category FOREIGN KEY (category_id) REFERENCES ticket_categories (id) ON DELETE SET NULL,
                CONSTRAINT fk_tickets_contact FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE SET NULL,
                CONSTRAINT fk_tickets_assigned FOREIGN KEY (assigned_to_user_id) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS tickets');
    }
};
